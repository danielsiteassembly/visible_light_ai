# Luna AI Chat WordPress Plugin — Client Guide

This guide walks through installing the Luna AI Chat plugin, connecting it to your Visible Light (VL) Hub profile, and managing day-to-day maintenance tasks from the WordPress admin dashboard.

## 1. Install and activate the plugin
1. Download the latest Luna AI Chat plugin ZIP file supplied by Visible Light.
2. In your WordPress dashboard, go to **Plugins ▸ Add New** and choose **Upload Plugin**.
3. Select the downloaded ZIP, click **Install Now**, and wait for the upload to finish.
4. Click **Activate Plugin** to enable Luna AI Chat. Activation creates the **Luna Widget** menu in the WordPress admin sidebar and schedules the hourly background heartbeat that keeps your license in sync.

## 2. Configure the plugin and connect to VL Hub
1. Navigate to **Luna Widget ▸ Settings**.
2. Enter your **Corporate License Code** exactly as it appears in VL Hub. This key unlocks Hub-powered content, analytics, and sync endpoints for your site.
3. Confirm the **License Server (Hub)** URL matches the Hub instance that issued your license. The plugin automatically enforces HTTPS and removes trailing slashes.
4. Pick an **Embedding mode**:
   - **Shortcode** renders Luna in specific posts or pages via `[luna_chat]`.
   - **Floating chat widget** injects the conversational widget site-wide.
5. Customize the **Widget UI** (title, avatar, greeting copy, and on-screen position) to match your brand.
6. (Optional) Paste an **OpenAI API key** if you want Luna to blend deterministic Hub answers with generative AI completions. Without this key, Luna responds strictly from VL Hub content and locally cached facts.
7. Click **Save changes**. Saving stores the configuration locally and primes the connection helpers used by the Hub endpoints.

## 3. "Test Connection" (Test Activation)
The banner at the top of the Settings page shows the most recent Hub response code. Click **Test Activation** to run a connection check:
- WordPress posts an activation request to the VL Hub license endpoint using your stored license and site details.
- The response (status code, timestamp, and any error message) is saved and displayed in the banner so you can confirm the Hub handshake succeeded.
- Use this whenever you add a new license, migrate environments, or change the Hub URL.

## 4. Use "Heartbeat Now"
Luna automatically sends a heartbeat to VL Hub every hour, but the **Heartbeat Now** button lets you trigger it immediately:
- Heartbeats verify the license is still valid and refresh the **Hub connection** banner with the latest response.
- Trigger a heartbeat after fixing connectivity issues or when the Hub team asks for a real-time status check.

## 5. "Sync with Hub"
Click **Sync to Hub** to push the latest site data to VL Hub on demand:
- The plugin bundles your license, Hub URL, embedding mode, widget settings, site URL, WordPress version, plugin version, saved keyword mappings, and stored GA4 analytics settings.
- This payload is sent to the Hub so profile records stay aligned with the WordPress source of truth.
- Run a manual sync after updating keywords, refreshing analytics credentials, or adjusting widget branding so Luna’s remote profile updates immediately.

## 6. Deactivate and uninstall
1. Go to **Plugins ▸ Installed Plugins** and locate **Luna Chat — Widget (Client)**.
2. Click **Deactivate** to stop the widget, heartbeat, and scheduled syncs. The admin pages remain available for review until you deactivate.
3. (Optional) Click **Delete** to uninstall the plugin. WordPress removes the plugin files; any stored options (license, settings, security overrides) are left in the database in case you reinstall later.

Need additional help? Reach out to the Visible Light support team with your site URL and license code so they can review your Hub profile configuration.
