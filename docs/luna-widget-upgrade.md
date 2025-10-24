# Luna Chat Widget Upgrade Guide

This guide walks through replacing the legacy "Luna Chat — Widget (Client)" plugin with the consolidated release that bundles the composer, widget, and Visible Light Hub account selector.

## 1. Prepare the site

1. Log in to the WordPress admin dashboard using an account with administrator privileges.
2. Navigate to **Dashboard → Updates** and make a quick backup (database + `wp-content` directory) so you can roll back if needed.
3. Note any pages or templates that still reference the old theme script (`wp-content/themes/supercluster/assets/javascript/luna-chat.js`). These should render the `[luna_composer]` shortcode after the upgrade.

## 2. Deactivate and remove the legacy plugin

1. Go to **Plugins → Installed Plugins**.
2. Locate **Luna Chat — Widget (Client)**. If an older ZIP was manually uploaded (for example `luna-widget-only.zip`), deactivate it.
3. Click **Delete** to remove the plugin files from `wp-content/plugins/luna-widget-only/`.
4. (Optional) If you cannot delete from the dashboard, use SFTP/SSH to remove the directory manually:
   ```bash
   rm -rf wp-content/plugins/luna-widget-only
   ```

## 3. Upload the updated plugin

1. From the admin dashboard, choose **Plugins → Add New → Upload Plugin**.
2. Upload the new ZIP that contains the following files:
   - `wp-content/plugins/luna-widget-only/luna-widget-only.php`
   - `wp-content/plugins/luna-widget-only/assets/js/luna-composer.js`
   - `wp-content/plugins/luna-widget-only/assets/js/luna-composer-hub.js`
3. Click **Install Now**, then **Activate**.

If you prefer SFTP/SSH deployment:

```bash
unzip luna-widget-only-latest.zip -d wp-content/plugins/
``` 

This will recreate the `luna-widget-only` folder with the updated PHP and JavaScript assets.

## 4. Verify plugin settings

1. After activation, a unified **Luna Widget → Compose** admin screen is available. Visit it to confirm:
   - Composer activation checkbox state.
   - Default Hub account selection (including demo accounts sourced from Visible Light Hub).
   - Canned prompts and recent chat history.
2. Ensure the `[luna_composer]` shortcode is present on any page that should expose the composer. The shortcode now enqueues `assets/js/luna-composer.js` automatically; no theme-level script is required.
3. On Hub-connected installs, the admin composer interface loads `assets/js/luna-composer-hub.js` so you can switch between client and demo accounts.

## 5. Clean up deprecated theme assets

1. Remove `wp-content/themes/supercluster/assets/javascript/luna-chat.js` if it only existed to power the old composer experience.
2. Update the child theme enqueue logic (in `wp-content/themes/supercluster/functions.php`) to skip loading the legacy script when the plugin is active. The repository version already guards this for you.
3. Flush any page cache or CDN so the new scripts are served.

## 6. Post-upgrade checks

1. Visit the public site pages that host the widget and composer. Confirm that both function correctly and that selecting demo clients in the Hub-powered composer returns responses scoped to the chosen account.
2. Check **Plugins → Plugin File Editor** (or inspect via SFTP) to verify that only the three files listed above changed during the upgrade.
3. Review the latest entries under **Luna Widget → Compose → History** to ensure new chats are being logged with the correct account metadata.

## Summary of changed files

| Path | Purpose |
| ---- | ------- |
| `wp-content/plugins/luna-widget-only/luna-widget-only.php` | Main plugin bootstrap, REST endpoints, admin pages, and settings. |
| `wp-content/plugins/luna-widget-only/assets/js/luna-composer.js` | Front-end composer for client sites (shortcode-rendered). |
| `wp-content/plugins/luna-widget-only/assets/js/luna-composer-hub.js` | Admin/HUB composer with account selection. |
| `wp-content/themes/supercluster/functions.php` *(optional cleanup)* | Guards theme enqueue so the removed `luna-chat.js` file is no longer required. |

After completing these steps, the site will be running the consolidated Luna plugin with Visible Light Hub integration and no conflicting theme scripts.
