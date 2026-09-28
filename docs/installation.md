---
sidebar_position: 2
---

# Installation

This guide walks you through installing Scholiq on your Nextcloud instance.

## Prerequisites

Before installing Scholiq, ensure your environment meets these requirements:

| Requirement | Minimum version | Notes |
|---|---|---|
| Nextcloud | 28.0 | Server must be running |
| PHP | 8.1 | 8.2+ recommended |
| OpenRegister | latest | Required; Scholiq stores all data via OpenRegister |
| OpenConnector | latest | Required; handles BRON/ROD, UWLR, OSO, Edukoppeling adapters |
| PostgreSQL | 14 | Recommended database backend for OpenRegister |

## Install from the App Store

1. Log in to your Nextcloud as an administrator.
2. Open the **Apps** menu (top-right user menu, or navigate to `/settings/apps`).
3. Search for **Scholiq**.
4. Click **Download and enable**.
5. Wait for the installation to complete. Nextcloud will download the app and run the repair steps automatically.

## Manual installation (development)

If you are installing from source or a release archive:

```bash
# Navigate to your Nextcloud custom_apps directory
cd /var/www/html/custom_apps

# Clone or unpack Scholiq
git clone https://github.com/ConductionNL/learniq.git scholiq

# Install PHP dependencies
cd scholiq && composer install --no-dev

# Install JavaScript dependencies and build
npm install --legacy-peer-deps && npm run build

# Enable the app
php /var/www/html/occ app:enable scholiq
```

## Initial configuration

After installation, complete the setup wizard:

1. Navigate to **Administration settings** (gear icon, then **Administration**).
2. Open the **Scholiq** section in the left sidebar.
3. The app will prompt you to configure the following registers in OpenRegister:
   - **Courses register**: stores course definitions, modules, and lessons
   - **Enrolments register**: stores learner enrolments and progress records
   - **Credentials register**: stores certificates and digital badges
   - **Compliance register**: stores compliance-training completions and audit logs
4. Click **Initialize registers** to create the default register and schema configuration.
5. Optionally configure OpenConnector source connections for:
   - DUO BRON/ROD (student registration)
   - UWLR (learning result exchange)
   - OSO (student transfer dossier)
   - SURFconext (higher-education SSO)

## Example data and the kind of organisation

The first time an administrator opens Learniq, the setup wizard asks two questions.

1. **Which example data do you want?** Pick the set that matches your organisation: primary school, secondary school, MBO, HBO/WO, company or training institute, as far as they ship in your version. Each set is one fictional organisation with its own people, groups and history. "Every schema, generated values" fills every list with generated values instead. Pick "None" on a production install.
2. **What kind of organisation is this?** Pick one of the six kinds. Learniq stores it as the segment under **App settings**, where you can change it later. The wizard pre-selects the kind of the example set you loaded.

The kind of organisation decides which menus appear. An install where nobody chose yet keeps every menu. Once you choose, each kind hides what belongs to someone else:

| Menu | Primary school | Secondary school | MBO | HBO/WO | Company | Training institute |
|---|---|---|---|---|---|---|
| Compliance overview and external training | hidden | hidden | hidden | hidden | shown | shown |
| Engagement and course evaluation | hidden | hidden | shown | shown | shown | shown |
| Work placements (BPV) | hidden | hidden | shown | hidden | hidden | hidden |
| Study progress (BSA) | hidden | hidden | hidden | shown | shown | hidden |
| Exam board | hidden | shown | shown | shown | hidden | shown |
| Exam accommodations | hidden | shown | shown | shown | shown | shown |
| Applications and admissions rounds | hidden | shown | shown | shown | hidden | shown |
| Subject choices | hidden | shown | shown | shown | shown | hidden |
| School advies | shown | shown | hidden | hidden | hidden | hidden |
| Report cards and report periods | shown | shown | shown | shown | hidden | shown |
| Parent conferences | shown | shown | shown | shown | hidden | shown |
| Attendance flags and compulsory education reports | shown | shown | shown | shown | hidden | shown |

Everything else, such as people, groups, attendance records, the pupil dossier, group plans, and the accessibility and privacy pages under Compliance, shows for every kind. Hiding a menu is not an access control: each page still checks who may read its data.

A choice counts once it names who made it. The wizard always records you. If you set the kind on the **App settings** page instead, fill in "Set by" with your user name, or the app keeps every menu as if nobody chose. Loading example data never counts as a choice.

Loading a set twice adds nothing, because every example object has a fixed id.

To remove the set again, open the setup wizard and go to its last step, **Remove the example data**, then click the button. The example objects move to the trash of OpenRegister, so you can restore them; anything you made yourself stays. The step never runs by itself. Only an administrator or a member of `administration-managers` can choose the kind of organisation in the wizard.

On an OpenRegister that cannot remove imports from the wizard, or for a set loaded before it could, the step tells you to run the command on the server instead. It shows what it would remove; add `--apply` to remove it:

```bash
php occ learniq:example-set:remove po
php occ learniq:example-set:remove po --apply
```

The command hands the set's ids to OpenRegister's `openregister:objects:purge --force`, the one route OpenRegister offers for removing fixtures from archival schemas. It only ever removes objects the set itself shipped.

## First-login checklist

After the registers are initialised:

- [ ] Open Scholiq from the Nextcloud app menu
- [ ] Confirm the dashboard loads without errors
- [ ] Navigate to **Courses** and verify the register is reachable
- [ ] (Admin) Navigate to **Settings** and confirm all register connections are green
- [ ] (Higher ed) Configure your SURFconext entity ID under **Settings > Identity**

## Troubleshooting

**Scholiq shows a blank screen after install**
Run `php occ app:repair scholiq` to re-run the register initialisation step.

**"OpenRegister not found" error**
Install and enable OpenRegister before enabling Scholiq. Scholiq requires OpenRegister as a dependency.

**Dashboard shows "Connection error" on a register tile**
Check that OpenRegister is running and the register slugs match those configured in Scholiq's settings. Re-run **Initialize registers** if needed.

**Permission error on first open**
Ensure the Nextcloud `www-data` user has write access to the `custom_apps/scholiq` directory.

## Upgrading

Scholiq follows Nextcloud's standard upgrade path. When a new version is available:

1. The Nextcloud update notification will appear in **Administration > Overview**.
2. Click **Update** next to Scholiq, or run `php occ upgrade`.
3. The repair step will migrate any register schema changes automatically.

## Uninstalling

To remove Scholiq:

```bash
php /var/www/html/occ app:disable scholiq
php /var/www/html/occ app:remove scholiq
```

Note: this does not delete data stored in OpenRegister. To remove Scholiq data, delete the associated registers in OpenRegister's administration interface.
