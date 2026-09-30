# Print Tracker Desktop

This Windows desktop app monitors the current user's local and connected printer queues, records detected jobs in a local JSON log, and sends unsynced records to MySQL when configured.

This project is distributed under the MIT License. See [LICENSE](LICENSE).

## Setup

Install the Python dependencies, then launch `print_tracker.py` once:

```powershell
python -m pip install -r requirements.txt
python print_tracker.py
```

On first launch, the app adds itself to the current Windows account's startup list and opens the database settings. The tray icon's **Configure database...** item reopens those settings. The saved database password is encrypted with Windows DPAPI for the current account.

The app stores its configuration, JSON print log, and diagnostic log under `%LOCALAPPDATA%\PrintTracker`. The JSON file is `queue.json`; it is created when the app starts and retains records after MySQL sync, with `synced` set to `true`. Pending records are retried automatically. Use **Open JSON log folder** in the tray menu to locate the file.

Automatic startup is registered visibly under `HKCU\Software\Microsoft\Windows\CurrentVersion\Run` as `WiltonPrintTracker`.

## Build executable

From the project directory, run:

```powershell
python -m PyInstaller --onefile --noconsole --name PrintTracker --icon icon.ico --add-data "icon.ico;." print_tracker.py
```

The executable is created at `dist\PrintTracker.exe`. The icon is bundled for both the executable and the tray menu.

Pushing a version tag such as `v1.0.0` runs the Windows release workflow and attaches the executable to a GitHub Release. Until a trusted code-signing provider approves and signs the release, the generated executable is unsigned and may show Windows security warnings.

## Code signing

Publicly trusted signing requires an accepted code-signing certificate. For a qualifying open-source project, apply to [SignPath Foundation](https://signpath.org/apply). Acceptance and signing-service setup are separate from this repository's build workflow; do not distribute unsigned artifacts as trusted releases.

## Running behavior

Closing the settings window leaves the app running in the system tray. There is no Exit command in the tray menu. A desktop process can still be ended with Windows Task Manager or administrator tools; it will start again at the next sign-in. This is a per-user desktop app, not a tamper-proof Windows service.