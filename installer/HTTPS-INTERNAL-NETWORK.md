# HTTPS on your internal network (Reporting App + Face ID)

Face ID uses the device camera. Browsers only allow the camera on **secure** pages:

| URL type | Camera on phones? |
|----------|-------------------|
| `https://10.10.10.250/...` | Yes (after trusting the certificate) |
| `http://localhost` on the server PC | Yes |
| `http://10.10.10.250:8090` | Page loads; **camera blocked** |

Use HTTPS on your Reporting App IIS site so tablets and phones can use Face ID clock-in/out.

---

## Quick setup (this server)

Run **as Administrator** on the Reporting App server:

```powershell
cd "C:\Program Files\ReportingApp"
Set-ExecutionPolicy -Scope Process Bypass
.\installer\enable-https.ps1 -InstallPath "C:\Program Files\ReportingApp" -IpAddress "10.10.10.250"
```

The script will:

1. Back up `.env` under `storage\app\sqlite-backups\pre-https-{timestamp}\`
2. Pick port **443** if free, otherwise **8443**
3. Create a **self-signed** certificate for `10.10.10.250`
4. Add an IIS **https** binding (keeps existing HTTP on port 8090)
5. Open Windows Firewall for the HTTPS port
6. Set `APP_URL=https://10.10.10.250` (or with `:8443` if needed)
7. Run `artisan config:cache` and restart IIS

Verify:

```cmd
installer\diagnose.cmd
```

Open in a browser: `https://10.10.10.250/login`

---

## Self-signed certificate (default)

### On a PC

1. Open `https://10.10.10.250/login`
2. Browser warns the connection is not private → **Advanced** → **Proceed to 10.10.10.250**
3. Log in; open **Face ID** to enroll faces and copy the kiosk link

### On Android

1. Same Wi‑Fi as the server
2. Chrome → `https://10.10.10.250/login`
3. **Advanced** → **Proceed** (wording varies)
4. Open the kiosk URL from **Face ID → Kiosk link**

### On iPhone

1. Safari → `https://10.10.10.250/login`
2. **Show Details** → **visit this website**
3. Use the kiosk link from the Face ID tab

You must accept the warning **once per browser** on each device. Face ID camera works after that.

---

## Trusted certificate (no warnings) — Active Directory Certificate Services

Use this when you have **AD CS** on your domain and want domain PCs (and optionally phones) to trust HTTPS without warnings.

### 1. Issue a web server certificate

On the Reporting App server, create a certificate request with your internal hostname **and** IP in the subject alternative name:

```powershell
$dnsName = "reporting.yourdomain.local"   # internal DNS name
$ip = "10.10.10.250"

$cert = New-SelfSignedCertificate -DnsName @($dnsName, $ip) ...  # for testing only
# Production: submit a CSR to AD CS (certsrv) using the Web Server template
```

Submit the CSR at `http://your-ca-server/certsrv` → **Request a certificate** → **Advanced** → paste CSR.

Install the issued certificate into **Local Computer → Personal**.

### 2. Bind in IIS

Either use IIS Manager (**Bindings → https → select the AD CS cert**) or:

```powershell
.\installer\enable-https.ps1 `
    -InstallPath "C:\Program Files\ReportingApp" `
    -IpAddress "10.10.10.250" `
    -DnsName "reporting.yourdomain.local" `
    -CertificateThumbprint "PASTE_THUMBPRINT_HERE"
```

Set internal DNS so `reporting.yourdomain.local` → `10.10.10.250`.

Update `.env`:

```env
APP_URL=https://reporting.yourdomain.local
```

### 3. Deploy root CA to phones (optional, removes warnings on mobile)

1. Export your **root CA** certificate (.cer) from the CA server
2. **Android:** Settings → Security → Install certificate → CA certificate
3. **iPhone:** Install profile, then Settings → General → About → Certificate Trust Settings → enable trust
4. **Company MDM (Intune, etc.):** deploy **Trusted Root CA** profile to all devices

Domain-joined Windows PCs usually trust the CA automatically via Group Policy.

---

## Parameters for enable-https.ps1

| Parameter | Default | Description |
|-----------|---------|-------------|
| `-InstallPath` | `C:\Program Files\ReportingApp` | App folder |
| `-SiteName` | `ReportingApp` | IIS site name |
| `-IpAddress` | `10.10.10.250` | IP in cert SAN and APP_URL |
| `-DnsName` | (empty) | Optional hostname; used in cert and APP_URL if set |
| `-HttpsPort` | `0` = auto (443, else 8443) | HTTPS port |
| `-CertificateThumbprint` | (empty) | Use existing cert instead of self-signed |
| `-KeepHttpBinding` | on | Keep HTTP port 8090 |
| `-Force` | off | Replace existing HTTPS binding/cert on that port |

---

## Troubleshooting

| Problem | Fix |
|--------|-----|
| Page not loading on phone | Same Wi‑Fi; check firewall; try `https://10.10.10.250:8443` if 443 failed |
| Camera blocked | Must be `https://`, not `http://` |
| Certificate warning | Normal for self-signed; proceed once, or use AD CS + root CA on phones |
| 404 on `/login` | Install IIS URL Rewrite; run `installer\diagnose.cmd` |
| Wrong site after HTTPS | Confirm `APP_URL` in `.env` matches the URL in the browser |

---

## Optional next steps

- **HTTP → HTTPS redirect** for port 8090 (IIS URL Rewrite rule)
- **Rotate** Windows administrator password if it was shared during setup
- **SQLite backup** before major changes: **Settings → SQLite backups**
