import base64
import datetime
import hashlib
import hmac
import json
import os
import queue
import secrets
import socket
import sys
import threading
import tkinter as tk
import winreg
from pathlib import Path
from tkinter import messagebox, simpledialog, ttk

import mysql.connector
import pystray
from pynput import keyboard, mouse
import win32api
import win32crypt
import win32event
import win32print
import winerror
from PIL import Image
from mysql.connector import Error


APP_NAME = "Print Tracker"
ADMIN_PASSWORD_ITERATIONS = 600_000
ACTIVITY_INTERVALS = (10, 20, 30, 40, 50, 60)
APP_DIR = Path(os.environ.get("LOCALAPPDATA", Path.home())) / "PrintTracker"
CONFIG_FILE = APP_DIR / "config.json"
QUEUE_FILE = APP_DIR / "queue.json"
ACTIVITY_FILE = APP_DIR / "activity_log.jsonl"
ACTIVITY_QUEUE_FILE = APP_DIR / "activity_queue.json"
LOG_FILE = APP_DIR / "print_tracker.log"
ICON_FILE = Path(__file__).resolve().with_name("icon.ico")
STARTUP_KEY = r"Software\Microsoft\Windows\CurrentVersion\Run"
STARTUP_VALUE = "WiltonPrintTracker"
DEFAULT_CONFIG = {
    "host": "",
    "user": "",
    "password": "",
    "admin_password_salt": "",
    "admin_password_hash": "",
    "database": "print_tracking_db",
    "port": 3306,
    "activity_interval_minutes": 10,
}


def load_config():
    config = DEFAULT_CONFIG.copy()
    try:
        with CONFIG_FILE.open("r", encoding="utf-8") as config_file:
            saved = json.load(config_file)
        config.update(
            {
                key: saved[key]
                for key in (
                    "host",
                    "user",
                    "database",
                    "port",
                    "activity_interval_minutes",
                    "admin_password_salt",
                    "admin_password_hash",
                )
                if key in saved
            }
        )
        if config["activity_interval_minutes"] not in ACTIVITY_INTERVALS:
            config["activity_interval_minutes"] = 10
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


def activity_period_start(moment, interval_minutes):
    interval_seconds = interval_minutes * 60
    period_timestamp = int(moment.timestamp()) // interval_seconds * interval_seconds
    return datetime.datetime.fromtimestamp(period_timestamp)


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
        self.ui_commands = queue.Queue()
        self.config_window = None
        self.activity_lock = threading.Lock()
        self.activity_interval_minutes = self.config["activity_interval_minutes"]
        self.activity_counts = {}
        self.activity_listeners = []
        self.initialize_activity_queue()

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
            "activity_interval_minutes": config["activity_interval_minutes"],
            "admin_password_salt": config["admin_password_salt"],
            "admin_password_hash": config["admin_password_hash"],
            "password_dpapi": base64.b64encode(protected_password).decode("ascii"),
        }
        temporary_file = CONFIG_FILE.with_suffix(".tmp")
        with temporary_file.open("w", encoding="utf-8") as config_file:
            json.dump(saved, config_file, indent=2)
        os.replace(temporary_file, CONFIG_FILE)
        with self.config_lock:
            self.config = config.copy()

    def open_config_from_tray(self, icon, item):
        self.ui_commands.put("configure_database")

    def process_ui_commands(self):
        while True:
            try:
                command = self.ui_commands.get_nowait()
            except queue.Empty:
                break
            if command in ("configure_database", "open_data_folder"):
                self.authorize_tray_action(command)

        if not self.stop_event.is_set():
            self.root.after(50, self.process_ui_commands)

    def open_data_folder_from_tray(self, icon, item):
        self.ui_commands.put("open_data_folder")

    def authorize_tray_action(self, action):
        config = self.get_config()
        password_salt = config["admin_password_salt"]
        password_hash = config["admin_password_hash"]

        if not password_salt or not password_hash:
            password = simpledialog.askstring(
                "Set admin password",
                "Create an admin password of at least 8 characters:",
                show="*",
                parent=self.root,
            )
            if password is None:
                return
            if len(password) < 8:
                messagebox.showerror(
                    "Invalid password",
                    "The admin password must be at least 8 characters.",
                    parent=self.root,
                )
                return
            confirmation = simpledialog.askstring(
                "Confirm admin password",
                "Enter the admin password again:",
                show="*",
                parent=self.root,
            )
            if confirmation is None:
                return
            if password != confirmation:
                messagebox.showerror("Password mismatch", "The passwords do not match.", parent=self.root)
                return

            password_salt = secrets.token_bytes(16).hex()
            password_hash = hashlib.pbkdf2_hmac(
                "sha256",
                password.encode("utf-8"),
                bytes.fromhex(password_salt),
                ADMIN_PASSWORD_ITERATIONS,
            ).hex()
            config["admin_password_salt"] = password_salt
            config["admin_password_hash"] = password_hash
            try:
                self.save_config(config)
            except Exception as error:
                messagebox.showerror("Could not save admin password", str(error), parent=self.root)
                return
        else:
            password = simpledialog.askstring(
                "Admin password",
                "Enter the admin password:",
                show="*",
                parent=self.root,
            )
            if password is None:
                return
            entered_hash = hashlib.pbkdf2_hmac(
                "sha256",
                password.encode("utf-8"),
                bytes.fromhex(password_salt),
                ADMIN_PASSWORD_ITERATIONS,
            ).hex()
            if not hmac.compare_digest(entered_hash, password_hash):
                messagebox.showerror("Access denied", "The admin password is incorrect.", parent=self.root)
                return

        if action == "configure_database":
            self.show_config()
        elif action == "open_data_folder":
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
        activity_interval = tk.StringVar(
            master=window, value=str(config.get("activity_interval_minutes", 10))
        )
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

        ttk.Label(frame, text="Record activity totals every").grid(
            row=6, column=0, sticky="w", pady=(10, 0)
        )
        ttk.Combobox(
            frame,
            textvariable=activity_interval,
            values=tuple(str(interval) for interval in ACTIVITY_INTERVALS),
            state="readonly",
            width=5,
        ).grid(row=6, column=1, sticky="w", pady=(10, 0))
        ttk.Label(
            frame,
            text="Stores interval totals locally only; key values and pointer positions are not saved.",
            foreground="#555555",
            wraplength=360,
        ).grid(row=7, column=0, columnspan=2, sticky="w", pady=(4, 10))

        connection_status = tk.StringVar(master=window)
        ttk.Label(frame, textvariable=connection_status).grid(
            row=8, column=0, columnspan=2, sticky="w", pady=(0, 8)
        )
        buttons = ttk.Frame(frame)
        buttons.grid(row=9, column=0, columnspan=2, sticky="ew")
        ttk.Button(
            buttons,
            text="Test connection",
            command=lambda: self.test_connection(fields, connection_status, window),
        ).pack(side="left")
        ttk.Button(buttons, text="Cancel", command=window.destroy).pack(side="right", padx=(8, 0))
        ttk.Button(
            buttons,
            text="Save",
            command=lambda: self.save_from_form(fields, activity_interval, window),
        ).pack(side="right")
        ttk.Label(
            frame,
            text="© 2026 Korbiz Solutions. Designed to support secure, scalable IT operations.",
            foreground="#666666",
            font=("Segoe UI", 8),
            wraplength=360,
            justify="left",
        ).grid(row=10, column=0, columnspan=2, sticky="w", pady=(12, 0))
        ttk.Label(
            frame,
            text="Starts automatically when this Windows account signs in.",
            foreground="#555555",
        ).grid(row=11, column=0, columnspan=2, sticky="w", pady=(8, 0))

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
                    use_pure=True,
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

    def save_from_form(self, fields, activity_interval, window):
        form_config = self.config_from_form(fields, window)
        if form_config is None:
            return
        config = self.get_config()
        config.update(form_config)
        try:
            config["activity_interval_minutes"] = int(activity_interval.get())
        except ValueError:
            config["activity_interval_minutes"] = 0
        if config["activity_interval_minutes"] not in ACTIVITY_INTERVALS:
            messagebox.showerror(
                "Invalid activity interval",
                "Choose an activity interval between 10 and 60 minutes.",
                parent=window,
            )
            return

        try:
            self.save_config(config)
        except Exception as error:
            messagebox.showerror("Could not save settings", str(error), parent=window)
            return

        self.configure_activity_monitor(config["activity_interval_minutes"])
        self.set_status("Monitoring")
        messagebox.showinfo("Settings saved", "Database settings were saved.", parent=window)
        window.destroy()

    def run(self):
        self.root.after(0, self.process_ui_commands)
        self.icon.run_detached()
        config = self.get_config()
        self.configure_activity_monitor(config.get("activity_interval_minutes", 10))
        self.worker.start()
        if not self.config["host"]:
            self.root.after(400, lambda: self.authorize_tray_action("configure_database"))
        try:
            self.root.mainloop()
        except KeyboardInterrupt:
            self.shutdown()

    def shutdown(self):
        self.stop_event.set()
        for listener in self.activity_listeners:
            listener.stop()
        for listener in self.activity_listeners:
            listener.join(timeout=1)
        self.activity_listeners = []

        if self.worker.is_alive():
            self.worker.join(timeout=6)

        try:
            self.flush_activity_counts(force=True)
        except Exception as error:
            write_log(f"Could not save activity totals during shutdown: {error}")

        self.icon.stop()
        try:
            self.root.destroy()
        except tk.TclError:
            pass

    def monitor_print_jobs(self):
        seen_jobs = set()
        while not self.stop_event.is_set():
            try:
                self.process_print_jobs(seen_jobs)
                self.flush_activity_counts()
                self.sync_to_mysql()
                if not self.get_config()["host"]:
                    self.set_status("Monitoring; database not configured")
                elif self.get_status() == "Starting":
                    self.set_status("Monitoring")
            except Exception as error:
                self.set_status("An error occurred; see the log")
                write_log(f"Tracker error: {error}")
            self.stop_event.wait(3)

    def record_activity(self, count_name):
        moment = datetime.datetime.now()
        with self.activity_lock:
            period_start = activity_period_start(moment, self.activity_interval_minutes)
            counts = self.activity_counts.setdefault(
                period_start,
                {"mouse_movement_count": 0, "keyboard_stroke_count": 0},
            )
            counts[count_name] += 1

    def record_mouse_activity(self, _x, _y):
        self.record_activity("mouse_movement_count")

    def record_keyboard_activity(self, _key):
        self.record_activity("keyboard_stroke_count")

    def configure_activity_monitor(self, interval_minutes):
        if interval_minutes != self.activity_interval_minutes:
            flushed_at = datetime.datetime.now()
            with self.activity_lock:
                previous_interval = self.activity_interval_minutes
                self.write_activity_records(self.activity_counts, previous_interval, flushed_at)
                self.activity_counts = {}
                self.activity_interval_minutes = interval_minutes

        if not self.activity_listeners:
            try:
                self.activity_listeners = [
                    mouse.Listener(on_move=self.record_mouse_activity),
                    keyboard.Listener(on_press=self.record_keyboard_activity),
                ]
                for listener in self.activity_listeners:
                    listener.start()
            except Exception as error:
                for listener in self.activity_listeners:
                    listener.stop()
                self.activity_listeners = []
                write_log(f"Could not start activity monitoring: {error}")
                self.set_status("Activity monitoring could not start; see the log")
    def flush_activity_counts(self, force=False):
        now = datetime.datetime.now()
        with self.activity_lock:
            interval_minutes = self.activity_interval_minutes
            current_period = activity_period_start(now, interval_minutes)
            pending_counts = {
                period_start: self.activity_counts[period_start]
                for period_start in tuple(self.activity_counts)
                if force or period_start < current_period
            }
            self.write_activity_records(pending_counts, interval_minutes, now)
            for period_start in pending_counts:
                del self.activity_counts[period_start]

    def write_activity_records(self, counts_by_period, interval_minutes, flushed_at):
        if not counts_by_period:
            return
        APP_DIR.mkdir(parents=True, exist_ok=True)
        activity_queue = self.read_activity_queue()
        records = []
        for period_start, counts in sorted(counts_by_period.items()):
            period_end = min(
                period_start + datetime.timedelta(minutes=interval_minutes), flushed_at
            )
            record = {
                "interval_start": period_start.isoformat(),
                "interval_end": period_end.isoformat(),
                "interval_minutes": interval_minutes,
                **counts,
            }
            records.append(record)
            activity_queue.append(
                {
                    "hostname": socket.gethostname(),
                    **record,
                    "synced": False,
                }
            )
        self.write_activity_queue(activity_queue)
        with ACTIVITY_FILE.open("a", encoding="utf-8") as activity_file:
            for record in records:
                activity_file.write(json.dumps(record) + "\n")

    def initialize_activity_queue(self):
        if ACTIVITY_QUEUE_FILE.exists():
            return

        activity_queue = []
        try:
            with ACTIVITY_FILE.open("r", encoding="utf-8") as activity_file:
                for line in activity_file:
                    saved_record = json.loads(line)
                    interval_start = datetime.datetime.fromisoformat(
                        saved_record.get("interval_start", saved_record.get("minute"))
                    )
                    interval_minutes = int(saved_record.get("interval_minutes", 1))
                    interval_end = saved_record.get("interval_end")
                    if interval_end is None:
                        interval_end = min(
                            interval_start + datetime.timedelta(minutes=interval_minutes),
                            datetime.datetime.now(),
                        ).isoformat()
                    activity_queue.append(
                        {
                            "hostname": socket.gethostname(),
                            "interval_start": interval_start.isoformat(),
                            "interval_end": interval_end,
                            "interval_minutes": interval_minutes,
                            "mouse_movement_count": int(
                                saved_record.get(
                                    "mouse_movement_count", saved_record.get("mouse_movements", 0)
                                )
                            ),
                            "keyboard_stroke_count": int(
                                saved_record.get(
                                    "keyboard_stroke_count", saved_record.get("keyboard_presses", 0)
                                )
                            ),
                            "synced": False,
                        }
                    )
        except FileNotFoundError:
            pass
        except (ValueError, TypeError, json.JSONDecodeError) as error:
            write_log(f"Could not migrate activity log: {error}")

        self.write_activity_queue(activity_queue)

    def read_activity_queue(self):
        try:
            with ACTIVITY_QUEUE_FILE.open("r", encoding="utf-8") as activity_queue_file:
                return json.load(activity_queue_file)
        except FileNotFoundError:
            return []
        except Exception as error:
            write_log(f"Could not read activity queue: {error}")
            return []

    def write_activity_queue(self, data):
        temporary_file = ACTIVITY_QUEUE_FILE.with_suffix(".tmp")
        with temporary_file.open("w", encoding="utf-8") as activity_queue_file:
            json.dump(data, activity_queue_file, indent=2)
        os.replace(temporary_file, ACTIVITY_QUEUE_FILE)

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

    def get_device_id(self, cursor, hostname, device_ids):
        if hostname not in device_ids:
            cursor.execute(
                """
                INSERT INTO devices (hostname)
                VALUES (%s)
                ON DUPLICATE KEY UPDATE device_id = LAST_INSERT_ID(device_id)
                """,
                (hostname,),
            )
            device_ids[hostname] = cursor.lastrowid
        return device_ids[hostname]

    def sync_to_mysql(self):
        config = self.get_config()
        if not config["host"] or not config["user"] or not config["database"]:
            self.set_status("Monitoring; database not configured")
            return

        queue = self.read_queue()
        unsynced = [item for item in queue if not item.get("synced", False)]
        activity_queue = self.read_activity_queue()
        unsynced_activity = [item for item in activity_queue if not item.get("synced", False)]
        if not unsynced and not unsynced_activity:
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
                use_pure=True,
            )
            cursor = connection.cursor()
            device_ids = {}
            insert_query = """
                INSERT INTO print_logs (device_id, document_name, copies, total_pages, print_timestamp)
                VALUES (%s, %s, %s, %s, %s)
            """
            for item in unsynced:
                device_id = self.get_device_id(cursor, item["hostname"], device_ids)
                cursor.execute(
                    insert_query,
                    (
                        device_id,
                        item["document_name"],
                        item["copies"],
                        item["total_pages"],
                        item["timestamp"],
                    ),
                )
            activity_insert_query = """
                INSERT INTO activity_logs (
                    device_id,
                    interval_start,
                    interval_end,
                    interval_minutes,
                    mouse_movement_count,
                    keyboard_stroke_count
                )
                VALUES (%s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    interval_end = VALUES(interval_end),
                    mouse_movement_count = VALUES(mouse_movement_count),
                    keyboard_stroke_count = VALUES(keyboard_stroke_count)
            """
            for item in unsynced_activity:
                device_id = self.get_device_id(cursor, item["hostname"], device_ids)
                cursor.execute(
                    activity_insert_query,
                    (
                        device_id,
                        datetime.datetime.fromisoformat(item["interval_start"]),
                        datetime.datetime.fromisoformat(item["interval_end"]),
                        item["interval_minutes"],
                        item["mouse_movement_count"],
                        item["keyboard_stroke_count"],
                    ),
                )
            connection.commit()
            for item in unsynced:
                item["synced"] = True
            self.write_queue(queue)
            for item in unsynced_activity:
                item["synced"] = True
            self.write_activity_queue(activity_queue)
            self.set_status("Monitoring; database connected")
        except Error as error:
            self.set_status("Database unavailable; records are queued")
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