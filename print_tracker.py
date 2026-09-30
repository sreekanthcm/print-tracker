import base64
import datetime
import json
import os
import socket
import sys
import threading
import tkinter as tk
import winreg
from pathlib import Path
from tkinter import messagebox, ttk

import mysql.connector
import pystray
import win32api
import win32crypt
import win32event
import win32print
import winerror
from PIL import Image
from mysql.connector import Error


APP_NAME = "Print Tracker"
APP_DIR = Path(os.environ.get("LOCALAPPDATA", Path.home())) / "PrintTracker"
CONFIG_FILE = APP_DIR / "config.json"
QUEUE_FILE = APP_DIR / "queue.json"
LOG_FILE = APP_DIR / "print_tracker.log"
ICON_FILE = Path(__file__).resolve().with_name("icon.ico")
STARTUP_KEY = r"Software\Microsoft\Windows\CurrentVersion\Run"
STARTUP_VALUE = "WiltonPrintTracker"
DEFAULT_CONFIG = {
    "host": "",
    "user": "",
    "password": "",
    "database": "print_tracking_db",
    "port": 3306,
}


def load_config():
    config = DEFAULT_CONFIG.copy()
    try:
        with CONFIG_FILE.open("r", encoding="utf-8") as config_file:
            saved = json.load(config_file)
        config.update({key: saved[key] for key in ("host", "user", "database", "port") if key in saved})
        encrypted_password = saved.get("password_dpapi")
        if encrypted_password:
            encrypted_bytes = base64.b64decode(encrypted_password)
            _, password = win32crypt.CryptUnprotectData(encrypted_bytes, None, None, None, 0)
            config["password"] = password.decode("utf-8")
    except FileNotFoundError:
        pass
    except Exception as error:
        write_log(f"Could not load settings: {error}")
    return config


def register_startup():
    python_exe = Path(sys.executable)
    if not getattr(sys, "frozen", False) and python_exe.name.lower() == "python.exe":
        windowless_exe = python_exe.with_name("pythonw.exe")
        if windowless_exe.exists():
            python_exe = windowless_exe

    if getattr(sys, "frozen", False):
        command = f'"{python_exe}"'
    else:
        command = f'"{python_exe}" "{Path(__file__).resolve()}"'
    with winreg.CreateKey(winreg.HKEY_CURRENT_USER, STARTUP_KEY) as startup_key:
        try:
            current, _ = winreg.QueryValueEx(startup_key, STARTUP_VALUE)
        except FileNotFoundError:
            current = None
        if current != command:
            winreg.SetValueEx(startup_key, STARTUP_VALUE, 0, winreg.REG_SZ, command)


def write_log(message):
    APP_DIR.mkdir(parents=True, exist_ok=True)
    with LOG_FILE.open("a", encoding="utf-8") as log_file:
        log_file.write(f"{datetime.datetime.now().isoformat()} {message}\n")


class PrintTrackerApp:
    def __init__(self, root):
        APP_DIR.mkdir(parents=True, exist_ok=True)
        if not QUEUE_FILE.exists():
            self.write_queue([])
        self.root = root
        self.root.withdraw()
        self.config = load_config()
        self.config_lock = threading.Lock()
        self.status_lock = threading.Lock()
        self.status = "Starting"
        self.stop_event = threading.Event()
        self.config_window = None

        menu = pystray.Menu(
            pystray.MenuItem("Configure database...", self.open_config_from_tray),
            pystray.MenuItem("Open JSON log folder", self.open_data_folder_from_tray),
            pystray.MenuItem(
                lambda item: f"Status: {self.get_status()}",
                lambda icon, item: None,
                enabled=False,
            ),
        )
        with Image.open(ICON_FILE) as icon_image:
            self.icon = pystray.Icon(APP_NAME, icon_image.copy(), APP_NAME, menu)

        self.worker = threading.Thread(target=self.monitor_print_jobs, daemon=True)

    def get_status(self):
        with self.status_lock:
            return self.status

    def set_status(self, status):
        with self.status_lock:
            self.status = status

    def get_config(self):
        with self.config_lock:
            return self.config.copy()

    def save_config(self, config):
        protected_password = win32crypt.CryptProtectData(
            config["password"].encode("utf-8"), APP_NAME, None, None, None, 0
        )
        saved = {
            "host": config["host"],
            "user": config["user"],
            "database": config["database"],
            "port": config["port"],
            "password_dpapi": base64.b64encode(protected_password).decode("ascii"),
        }
        temporary_file = CONFIG_FILE.with_suffix(".tmp")
        with temporary_file.open("w", encoding="utf-8") as config_file:
            json.dump(saved, config_file, indent=2)
        os.replace(temporary_file, CONFIG_FILE)
        with self.config_lock:
            self.config = config.copy()

    def open_config_from_tray(self, icon, item):
        self.root.after(0, self.show_config)

    def open_data_folder_from_tray(self, icon, item):
        os.startfile(str(APP_DIR))

    def show_config(self):
        if self.config_window and self.config_window.winfo_exists():
            self.config_window.deiconify()
            self.config_window.lift()
            self.config_window.focus_force()
            return

        window = tk.Toplevel(self.root)
        self.config_window = window
        window.title("Print Tracker Settings")
        window.resizable(False, False)
        window.protocol("WM_DELETE_WINDOW", window.destroy)
        window.deiconify()
        window.lift()
        window.focus_force()
        try:
            window.iconbitmap(str(ICON_FILE))
        except tk.TclError:
            pass

        frame = ttk.Frame(window, padding=18)
        frame.grid(sticky="nsew")
        ttk.Label(frame, text="Database connection", font=("Segoe UI", 12, "bold")).grid(
            row=0, column=0, columnspan=2, sticky="w", pady=(0, 12)
        )

        config = self.get_config()
        fields = {}
        field_specs = (
            ("Host", "host", ""),
            ("Port", "port", ""),
            ("Database", "database", ""),
            ("Username", "user", ""),
            ("Password", "password", "*"),
        )
        for row, (label, key, mask) in enumerate(field_specs, start=1):
            ttk.Label(frame, text=label).grid(row=row, column=0, sticky="w", padx=(0, 14), pady=4)
            entry = ttk.Entry(frame, width=34, show=mask)
            entry.insert(0, str(config[key]))
            entry.grid(row=row, column=1, sticky="ew", pady=4)
            fields[key] = entry

        ttk.Label(
            frame,
            text="Starts automatically when this Windows account signs in.",
            foreground="#555555",
        ).grid(row=6, column=0, columnspan=2, sticky="w", pady=(12, 10))

        connection_status = tk.StringVar(master=window)
        ttk.Label(frame, textvariable=connection_status).grid(
            row=7, column=0, columnspan=2, sticky="w", pady=(0, 8)
        )
        buttons = ttk.Frame(frame)
        buttons.grid(row=8, column=0, columnspan=2, sticky="ew")
        ttk.Button(
            buttons,
            text="Test connection",
            command=lambda: self.test_connection(fields, connection_status, window),
        ).pack(side="left")
        ttk.Button(buttons, text="Cancel", command=window.destroy).pack(side="right", padx=(8, 0))
        ttk.Button(buttons, text="Save", command=lambda: self.save_from_form(fields, window)).pack(side="right")
        ttk.Label(
            frame,
            text="© 2026 Korbiz Solutions. Designed to support secure, scalable IT operations.",
            foreground="#666666",
            font=("Segoe UI", 8),
            wraplength=360,
            justify="left",
        ).grid(row=9, column=0, columnspan=2, sticky="w", pady=(12, 0))

        fields["host"].focus_set()

    def config_from_form(self, fields, window):
        config = {
            key: field.get() if key == "password" else field.get().strip()
            for key, field in fields.items()
        }
        if not config["host"] or not config["user"] or not config["database"]:
            messagebox.showerror("Missing settings", "Host, database, and username are required.", parent=window)
            return None
        try:
            config["port"] = int(config["port"])
            if not 1 <= config["port"] <= 65535:
                raise ValueError
        except ValueError:
            messagebox.showerror("Invalid port", "Enter a port between 1 and 65535.", parent=window)
            return None
        return config

    def test_connection(self, fields, connection_status, window):
        config = self.config_from_form(fields, window)
        if config is None:
            return
        connection_status.set("Testing connection...")

        def check_connection():
            connection = None
            try:
                connection = mysql.connector.connect(
                    host=config["host"],
                    user=config["user"],
                    password=config["password"],
                    database=config["database"],
                    port=config["port"],
                    connect_timeout=5,
                )
                result = "Connection successful." if connection.is_connected() else "Connection failed."
            except Error as error:
                result = f"Connection failed: {error}"
            finally:
                if connection:
                    connection.close()

            def show_result():
                if window.winfo_exists():
                    connection_status.set(result)

            self.root.after(0, show_result)

        threading.Thread(target=check_connection, daemon=True).start()

    def save_from_form(self, fields, window):
        config = self.config_from_form(fields, window)
        if config is None:
            return

        try:
            self.save_config(config)
        except Exception as error:
            messagebox.showerror("Could not save settings", str(error), parent=window)
            return

        self.set_status("Monitoring")
        messagebox.showinfo("Settings saved", "Database settings were saved.", parent=window)
        window.destroy()

    def run(self):
        self.icon.run_detached()
        self.worker.start()
        if not self.config["host"]:
            self.root.after(400, self.show_config)
        self.root.mainloop()

    def monitor_print_jobs(self):
        seen_jobs = set()
        while not self.stop_event.is_set():
            try:
                self.process_print_jobs(seen_jobs)
                self.sync_to_mysql()
                if not self.get_config()["host"]:
                    self.set_status("Monitoring; database not configured")
                elif self.get_status() == "Starting":
                    self.set_status("Monitoring")
            except Exception as error:
                self.set_status("An error occurred; see the log")
                write_log(f"Tracker error: {error}")
            self.stop_event.wait(3)

    def read_queue(self):
        try:
            with QUEUE_FILE.open("r", encoding="utf-8") as queue_file:
                return json.load(queue_file)
        except FileNotFoundError:
            return []
        except Exception as error:
            write_log(f"Could not read queue: {error}")
            return []

    def write_queue(self, data):
        temporary_file = QUEUE_FILE.with_suffix(".tmp")
        with temporary_file.open("w", encoding="utf-8") as queue_file:
            json.dump(data, queue_file, indent=2)
        os.replace(temporary_file, QUEUE_FILE)

    def log_print_job(self, document_name, copies, total_pages):
        record = {
            "hostname": socket.gethostname(),
            "document_name": document_name,
            "copies": copies,
            "total_pages": total_pages,
            "timestamp": datetime.datetime.now().isoformat(),
            "synced": False,
        }
        queue = self.read_queue()
        queue.append(record)
        self.write_queue(queue)

    def process_print_jobs(self, seen_jobs):
        printers = win32print.EnumPrinters(
            win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
        )
        for printer in printers:
            printer_name = printer[2]
            printer_handle = None
            try:
                printer_handle = win32print.OpenPrinter(printer_name)
                jobs = win32print.EnumJobs(printer_handle, 0, -1, 2)
                for job in jobs:
                    job_id = f"{printer_name}_{job['JobId']}"
                    if job_id in seen_jobs:
                        continue
                    devmode = job.get("pDevMode")
                    copies = devmode.Copies if devmode and hasattr(devmode, "Copies") else 1
                    self.log_print_job(
                        job.get("pDocument", "Unknown"),
                        copies,
                        job.get("TotalPages", 1),
                    )
                    seen_jobs.add(job_id)
            except Exception as error:
                write_log(f"Could not read printer {printer_name}: {error}")
            finally:
                if printer_handle:
                    win32print.ClosePrinter(printer_handle)

    def sync_to_mysql(self):
        config = self.get_config()
        if not config["host"] or not config["user"] or not config["database"]:
            self.set_status("Monitoring; database not configured")
            return

        queue = self.read_queue()
        unsynced = [item for item in queue if not item.get("synced", False)]
        if not unsynced:
            return

        connection = None
        cursor = None
        try:
            connection = mysql.connector.connect(
                host=config["host"],
                user=config["user"],
                password=config["password"],
                database=config["database"],
                port=config["port"],
                connect_timeout=5,
            )
            cursor = connection.cursor()
            insert_query = """
                INSERT INTO print_logs (hostname, document_name, copies, total_pages, print_timestamp)
                VALUES (%s, %s, %s, %s, %s)
            """
            for item in unsynced:
                cursor.execute(
                    insert_query,
                    (
                        item["hostname"],
                        item["document_name"],
                        item["copies"],
                        item["total_pages"],
                        item["timestamp"],
                    ),
                )
            connection.commit()
            for item in unsynced:
                item["synced"] = True
            self.write_queue(queue)
            self.set_status("Monitoring; database connected")
        except Error as error:
            self.set_status("Database unavailable; jobs are queued")
            write_log(f"Database sync failed: {error}")
        finally:
            if cursor:
                cursor.close()
            if connection:
                connection.close()


def main():
    mutex = win32event.CreateMutex(None, False, r"Local\WiltonPrintTrackerDesktop")
    if win32api.GetLastError() == winerror.ERROR_ALREADY_EXISTS:
        return

    try:
        register_startup()
    except OSError as error:
        write_log(f"Could not register automatic startup: {error}")

    root = tk.Tk()
    app = PrintTrackerApp(root)
    app.run()


if __name__ == "__main__":
    main()