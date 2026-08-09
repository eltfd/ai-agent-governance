=== AI Agent Governance ===
Contributors: indraa
Tags: ai, agent, governance, security, abilities-api
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later

Control, approve, and audit AI agent actions on your WordPress site.

== Description ==

AI Agent Governance sits between AI agents (ChatGPT, Claude, WPVibe, etc.) and your WordPress Abilities API. It provides:

* **Kill Switch** — instantly block all AI agent actions
* **Policy Rules** — wildcard pattern matching to allow, deny, or hold abilities
* **Approval Queue** — destructive actions require admin approval before execution
* **Audit Log** — complete trail of every AI agent attempt
* **Undo** — snapshot before destructive operations, restore with 1-click
* **Blocked List** — explicit blocklist for specific ability names or patterns

== Installation ==

1. Upload `ai-agent-governance` folder to `/wp-content/plugins/`
2. Activate through Plugins menu
3. Navigate to **AI Governance** in admin sidebar
4. Configure policies and blocked abilities

== Usage Guide ==

=== Quick Setup ===
1. **Activate Plugin**: Go to **Plugins > Installed Plugins** and activate **AI Agent Governance**.
2. **Access Governance Dashboard**: Click **AI Governance** in the main WP admin menu.

=== Core Features & Operations ===

* **Emergency Control (Kill Switch)**
  - Toggle the **Emergency Kill Switch** at top of page to `ON` to instantly block ALL AI agent actions across site.
  - Turn `OFF` to restore normal governance routing.

* **Managing Policies & Rules**
  - Click **Add Rule** under Policy Management.
  - Set **Pattern** (e.g. `posts/*`, `users/delete`, `*` for global default).
  - Select **Action**: `Allow` (pass through), `Deny` (block), or `Hold` (require admin review).
  - Rules evaluate top-down in display order.

* **Approval Queue (Held Actions)**
  - Actions marked `Hold` or flagged as `destructive` land in **Pending Approvals**.
  - Review action metadata and click **Approve** to execute or **Reject** to block.

* **Blocked List**
  - Add specific ability identifiers (e.g., `core/update-settings`) to **Blocked Abilities** for permanent block without rule evaluation.

* **Audit Trail**
  - Go to **Audit Log** tab to view timestamped history of all agent action attempts, parameters, decisions, and execution outcomes.

=== Telegram Notifications (Pro) ===

Get instant Telegram alerts when an AI action enters the approval queue.

**Setup:**
1. **Bot Token** — open Telegram, message `@BotFather`, send `/newbot`, choose a name and username, copy the token it returns.
2. **Chat ID** — message `@userinfobot`; it replies with `Id: 123456789` — that number is your Chat ID. Alternative: message your bot once, then open `https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates` in a browser and read the `chat.id` value.
3. Go to **AI Governance** dashboard → **Telegram Notifications** card, paste both values, tick **Enable Telegram alerts for pending approvals**, click **Save Telegram Settings**.

From then on, every held action sends a Telegram message with the ability name, reason, entry ID, and approve/reject link.

== Changelog ==

= 0.1.0 =
* Initial release
