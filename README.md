# JetFormBuilder WebP Upload PRO

[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**JetFormBuilder WebP Upload PRO** is a powerful extension for JetFormBuilder that solves a common web performance headache: users uploading massive, unoptimized images through your forms.

The plugin converts images (JPG/PNG) to **WebP in real-time** during the upload process, significantly improving loading speeds and saving server disk space.

---

## 🚀 Key Features

* **Real-time Conversion:** Automatic JPG/PNG to WebP transformation upon upload.
* **Media Library Management:** * *Keep Original:* Save both source and WebP.
    * *Replace Original:* Permanently delete the source file to keep your library clean.
* **WebP Quality Control:** Fine-tune compression levels via the control panel.
* **Smart Resizing:** Automatically downscale oversized images (e.g., from 5000px to 1920px) based on your Max Width settings.
* **Visual Loader:** Immediate UI feedback for users during the processing phase.
* **Full Support:** Works for both Email Attachments and Media Library uploads (Single & Multiple fields).

---

## 🛠 Technical Details

The plugin leverages server-side processing for maximum reliability:
* **Engine:** Requires **ImageMagick** or **GD Library** installed on the server.
* **Field Compatibility:** Specifically designed for the standard **"File Upload"** field.
* **Restriction:** Currently NOT compatible with the "Drag & Drop" addon.

---

## ⚙️ Installation

1. Download the latest release.
2. Upload the plugin folder to your `/wp-content/plugins/` directory.
3. Activate the plugin in WordPress.
4. Go to **JetFormBuilder > WebP Settings** to configure your optimization rules.

---

## 🧪 Beta Testing
This plugin is currently in a testing phase. If you encounter any bugs or have feature requests, please:
1. Open an **Issue** here on GitHub.
2. Provide details about your server environment (PHP version, GD/ImageMagick).
