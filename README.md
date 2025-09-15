## 🚦 Modpoll Web UI

Modern web UI for Modbus polling on Windows, powered by PHP and the embedded `modpoll.exe`. It provides an ergonomic interface to build commands, stream live output, and explore equipment presets or units from a local MySQL database.

- 🪟 Windows-first
- 🐘 PHP built-in server
- 🔌 Modbus RTU/TCP
- 📡 Live terminal via Server‑Sent Events (SSE)

---

### 🚀 Quick start

1) Double‑click `start_modpoll_server.bat`

- This launches a local PHP server at `http://localhost:8000` and opens your browser automatically.
- The app will ensure the embedded `modpoll.exe` exists and download it to this folder if missing.

2) In the UI:

- Click “Refresh” next to COM Port to detect serial ports
- Configure parameters (COM or TCP, baudrate, parity, address, etc.)
- Review the generated command preview
- Click “Start Polling” to run and stream output in real‑time
- Click “Stop” to terminate an in‑progress poll

If you prefer running manually, from this directory you can also run:

```bash
php -S localhost:8000
```

Then open `http://localhost:8000` in your browser.

---

### ✨ Key features

- 🔍 COM port discovery: Detects Windows serial ports via Registry and PowerShell fallback.
- 🧰 Argument builder: Generates correct `modpoll` flags for RTU and TCP, including edge-cases like `COM10+` paths.
- 📡 Live terminal: Streams output with colorized info/error classification using SSE.
- 🧠 Smart hints: Identifies common errors (e.g., “Port or socket open error!”, “Serial port already open”).
- 🧾 Command preview: Always shows the exact command that will run.
- 🛑 Controlled stop: Writes a PID file and cleanly stops the background process.
- 🗂️ Equipment presets: Quick‑apply defaults (baud, parity, bits) for many known devices.
- 🗃️ Optional MySQL integration: Fetches units and communication settings from `iw_plant_server3` if available.
- 📦 Embedded binary: Uses the bundled `modpoll.exe` in this folder; no system‑wide install required.

---

### 🧭 UI guide

- **Equipment panel**: Search and click a device to auto‑fill baudrate, parity, bits.
- **Settings**: Choose COM port (or enter `IP[:port]` for TCP), address, start ref, count, data/stop bits, parity, etc.
- **Terminal**:
  - “Start Polling” begins execution and streams logs
  - “Stop” terminates the process (also auto‑resets on fatal errors)
  - “Command” field shows the exact command; you can edit it for custom runs
- **Units table** (optional): Loads from MySQL when available. Default view shows Modbus‑supported units; toggle to view all.

---

### 🧩 How it works (deep dive)

The app is a single PHP file (`index.php`) that serves both the UI and a small API.

- 📁 Paths and config
  - `modpoll.exe` is expected in this directory. If missing, it downloads from:
    `https://github.com/spenz91/ModpollingTool/releases/download/modpollv2/modpoll.exe`
  - PID file: written to the system temp folder to support the Stop control
  - Optional MySQL config constants are defined at the top of `index.php`

- 🧭 Router actions (`?action=`)
  - `ensure_modpoll`: Verifies or downloads `modpoll.exe` if absent
  - `list_ports`: Enumerates Windows COM ports using Registry; falls back to PowerShell
  - `build`: Returns a preview and structured args for the `modpoll` command
  - `run`: Starts `modpoll.exe` with built args or a custom command and streams stdout/stderr via SSE
  - `stop`: Reads the PID file and terminates the process tree
  - `units`: Queries MySQL for unit data and inferred comms settings (see schema details below)

- 🧱 Argument building highlights
  - RTU mode: constructs flags like `COMx -b{baud} -p{parity} -a{addr} [-d][-s][-r][-c][-t]`
  - TCP mode: uses `-m tcp IP[:port]` plus the same data/stop/addr/ref/count/type flags
  - COM10+ handling: formats as `\\.\COM10` to satisfy Windows APIs
  - Parity normalization: accepts `n/none/0`, `e/even/2`, `o/odd/1`

- 📡 Streaming and classification
  - Uses PHP `proc_open` to spawn `modpoll.exe` and non‑blocking pipes to read output
  - Server‑Sent Events flushes lines to the browser in near real time
  - Lines are classified into `info`, `error`, or skipped boilerplate; known messages are human‑friendly

- 🗃️ MySQL units integration (optional)
  - Tries credentials in order: `(iwmac, ""), (root, "")` against database `iw_plant_server3`
  - Reads from:
    - `iw_sys_plant_units` (core unit metadata)
    - `iw_sys_plant_settings` (COM, baud/parity, IP, mb_mode, mb_tcp_servers)
  - Infers TCP targets, cleans IPs, maps parity values, and filters unsupported combinations
  - UI defaults to “Modbus‑supported units” only; toggle to show all

---

### 🧪 API endpoints

All endpoints are on the same page; use query string `?action=...`.

- `GET ?action=ensure_modpoll`
- `GET ?action=list_ports`
- `POST ?action=build` with form fields: `com, baudrate, parity, address, databits, stopbits, startref, count, dtype, tcp`
- `GET ?action=run&...` using the same fields as build, or `run&custom=<string>`
- `GET ?action=stop`
- `GET ?action=units`

Example previews:

```text
modpoll COM3 -b9600 -pnone -a1 -r100 -c1 -t3
modpoll -m tcp 192.168.1.100:502 -b19200 -peven -d8 -s1 -a1 -r100 -c10 -t3
```

---

### ⚙️ Configuration

Edit the top of `index.php` if needed:

- Database host/name and credential fallbacks
- Modpoll download URL
- Optional UI tweaks (equipment list is embedded in the page)

Requirements:

- Windows 10+
- PHP 7.4+ available on PATH (for `php -S` and stream wrappers)
- Network access to download `modpoll.exe` on first run

---

### 🐛 Troubleshooting

- ❌ Port or socket open error!
  - Likely the serial port is in use. Stop any plant server/service holding the COM port and try again.
- ❌ Serial port already open
  - Same as above; ensure exclusive access before polling.
- ⏱️ Reply time‑out!
  - Device not responding. Check wiring, address, and baud/parity.
- 🔐 COM10+ devices
  - The UI handles `\\.\COM10` formatting automatically; just select the port from the dropdown.

---

### 🔒 Security notes

- The app only executes the embedded `modpoll.exe` from this directory. Custom commands are sanitized to remove any leading `modpoll` path before execution.
- PHP’s built‑in server is for local use. Do not expose this tool directly to the internet.

---

### 📦 What’s included

- `index.php` — UI + API server
- `start_modpoll_server.bat` — one‑click launcher for the local server
- `modpoll.exe` — downloaded automatically on first run (stored locally)

---

### ⚠️ Limitations

- Built for Windows. COM port enumeration relies on Windows Registry or PowerShell.
- SSE requires a modern browser; if logs don’t appear, try Edge/Chrome/Firefox.
- PHP built‑in server is not intended for production use.

---

### 📝 License & attribution

- `modpoll` is part of FieldTalk. Refer to FieldTalk’s licensing terms for usage and redistribution of `modpoll.exe`.
- This UI is a convenience layer to streamline `modpoll` usage.

---

### 🙌 Credits

- Modpoll by FieldTalk
- UI/themes inspired by modern dark UIs


