# Face ID — enrollment fix (mobile + detection UX)

Server path: `C:\Program Files\ReportingApp`

## Recommended: run the update installer

After building on your dev PC, copy and run:

`E:\reporting app\dist\ReportingApp-Setup.exe`

On the server:

1. Run **as Administrator**
2. Choose the same folder: `C:\Program Files\ReportingApp`
3. Then:
   ```cmd
   cd "C:\Program Files\ReportingApp"
   runtime\php\php.exe artisan view:clear
   ```
4. Hard refresh Chrome on the phone

---

## Manual copy (if not using the installer)

| Copy from your PC | Paste on server |
|-------------------|-----------------|
| `resources\views\reports\face-id\index.blade.php` | same path |
| `resources\views\reports\face-id\partials\enroll-modal.blade.php` | same path |
| `public\js\face-id-enroll.js` | same path |

Create folder if needed: `resources\views\reports\face-id\partials\`

Then `artisan view:clear` and hard refresh on the phone.

---

## What’s new (v4)

- **Larger camera preview** (taller 3:4 frame, higher resolution request)
- **Oval face guide** with dimmed area outside
- **Tips** for neutral expression, lighting, one person
- **Live face detection** — green/yellow box feedback before capture
- **Too dark** warning — asks to move to a brighter area
- **Capture enabled only** when face is centered and lighting is OK

Script cache: `face-id-enroll.js?v=4`

---

## Checklist (easy to miss)

1. **Face-api models on server** — `public\face-api-models\` must have 7 files. Test in browser:
   `https://10.10.10.250/face-api-models/tiny_face_detector_model-weights_manifest.json`
2. **HTTPS** — required for camera (you already use this)
3. **Internet once** — `face-api.js` loads from jsDelivr CDN on first admin visit
4. **Enroll like the kiosk** — neutral face, indoor light, similar distance to tablet

---

## Xiaomi / Android

- Chrome only
- Camera permission for Chrome
- Battery: no restrictions for Chrome
