---
sidebar_position: 2
---

# Installation

This guide walks you through installing Learniq on your Nextcloud instance.

## Prerequisites

Before installing Learniq, ensure your environment meets these requirements:

| Requirement | Minimum version | Notes |
|---|---|---|
| Nextcloud | 28.0 | Server must be running |
| PHP | 8.1 | 8.2+ recommended |
| OpenRegister | latest | Required; Learniq stores all data via OpenRegister |
| portaliq and thematiq | latest | Optional; needed for the portals of the example sets |
| integriq | latest | Optional; carries exchanges such as BRON/ROD, UWLR and OSO |
| PostgreSQL | 14 | Recommended database backend for OpenRegister |

## Install from the App Store

1. Log in to your Nextcloud as an administrator.
2. Open the **Apps** menu (top-right user menu, or navigate to `/settings/apps`).
3. Search for **Learniq**.
4. Click **Download and enable**.
5. Wait for the installation to complete. Nextcloud will download the app and run the repair steps automatically.

## Manual installation (development)

If you install from source, turn off the app store first. Otherwise Nextcloud may fetch a released package over the app you cloned:

```bash
php /var/www/html/occ config:system:set appstoreenabled --value=false --type=boolean
```

Then install and build Learniq. The folder must be called `learniq`, the app id:

```bash
cd /var/www/html/custom_apps
git clone https://github.com/ConductionNL/learniq.git learniq
cd learniq && composer install --no-dev
npm ci && npm run build
php /var/www/html/occ app:enable learniq
```

For the portals of the example sets, portaliq and thematiq need the same treatment. portaliq has three bundles (the admin screens, the site and the traffic counter); `npm run build` builds all three. thematiq has no bundle to build:

```bash
cd /var/www/html/custom_apps
git clone https://github.com/ConductionNL/portaliq.git portaliq
(cd portaliq && composer install --no-dev && npm ci && npm run build)
git clone https://github.com/ConductionNL/thematiq.git thematiq
(cd thematiq && composer install --no-dev)
php /var/www/html/occ app:enable openregister
php /var/www/html/occ app:enable thematiq
php /var/www/html/occ app:enable portaliq
```

Enable OpenRegister before learniq and portaliq.

## Initial configuration

There is nothing to set up by hand. When you enable Learniq, it imports its register and schemas into OpenRegister. The first time an administrator opens Learniq, a short wizard asks two questions; see the next section.

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
| Study progress (BSA) | hidden | hidden | hidden | shown | hidden | hidden |
| Exam board | hidden | shown | shown | shown | hidden | shown |
| Exam accommodations | hidden | shown | shown | shown | shown | shown |
| Applications and admissions rounds | hidden | shown | shown | shown | hidden | shown |
| Subject choices | hidden | shown | shown | shown | hidden | hidden |
| School advies | shown | shown | hidden | hidden | hidden | hidden |
| Report cards and report periods | shown | shown | shown | shown | hidden | shown |
| Parent conferences | shown | shown | shown | shown | hidden | shown |
| Attendance flags and compulsory education reports | shown | shown | shown | shown | hidden | shown |

Everything else, such as people, groups, attendance records, the pupil dossier, group plans, and the accessibility and privacy pages under Compliance, shows for every kind. Hiding a menu is not an access control: each page still checks who may read its data.

A choice counts once it names who made it. The wizard always records you. If you set the kind on the **App settings** page instead, fill in "Set by" with your user name, or the app keeps every menu as if nobody chose. Loading example data never counts as a choice.

Loading a set twice adds nothing, because every example object has a fixed id. Loading two sets that ship the same regulation, such as the company and the training set with VCA and NIS2, keeps one row per regulation: the set you load second uses the row the first one made.

The setup wizard only loads example data; it does not remove it. Only an administrator or a member of `administration-managers` can choose the kind of organisation in the wizard.

To remove a set again, open the admin settings of learniq and go to **Example data**. Every set you loaded is listed with its own **Remove** button. After you confirm, its example objects move to the trash of OpenRegister, so you can restore them; anything you made yourself stays.

On an OpenRegister that cannot remove imports from there, or for a set loaded before it could, run the command on the server instead. It shows what it would remove; add `--apply` to remove it:

```bash
php occ learniq:example-set:remove po
php occ learniq:example-set:remove po --apply
```

The command hands the set's ids to OpenRegister's `openregister:objects:purge --force`, the one route OpenRegister offers for removing fixtures from archival schemas. It only ever removes objects the set itself shipped.

## Example portals: load a set from the command line

Each school set also brings its portal website: po builds De Wilgenboom, vo Vaartveld College, mbo Esdoornveen and training the Warmtepompacademie. You need portaliq and thematiq next to learniq. Load the sets one at a time:

```bash
php -d memory_limit=4G occ learniq:example-set:load po
php -d memory_limit=4G occ learniq:example-set:load vo
php -d memory_limit=4G occ learniq:example-set:load mbo
php -d memory_limit=4G occ learniq:example-set:load training
```

A first load takes four to fifteen minutes. A second load writes nothing and reports zero created. The load also creates the Nextcloud accounts of the staff the pages name; add `--no-accounts` to skip that.

### After the loads: run the repair step

A load leaves some readable copies empty, such as a pupil's name on her own records and the attendance summaries. Run the repair step once after the last load, then let cron empty its queue:

```bash
php occ maintenance:repair
php -d memory_limit=4G cron.php
```

The repair step takes a few minutes. Nextcloud is in maintenance mode meanwhile, so every page and API call answers 503 until it is done.

On a test instance without a cron container, run `cron.php` by hand until the queue is empty.

### Link the portals to your organisation

A loaded portal has no organisation and no identity provider. Binding those is a deployment step, so the load never guesses them. Set the organisation on each of the four portals, for example through the OpenRegister API, and then load each set once more.

First look up the ids. The portals list gives each portal's `id` next to its `slug`. The organisations list gives each organisation's `uuid`, which the next step needs:

```bash
curl -u admin:<password> -H 'OCS-APIRequest: true' https://<host>/apps/openregister/api/objects/portaliq/portal
curl -u admin:<password> -H 'OCS-APIRequest: true' https://<host>/apps/openregister/api/organisations
```

Then link each portal:

```bash
curl -u admin:<password> -X PATCH -H 'Content-Type: application/json' \
  -d '{"organisation":"default-organisation"}' \
  https://<host>/apps/openregister/api/objects/portaliq/portal/<portal id>
```

### Set the DigiD and eHerkenning issuer

There is no settings screen for an organisation's identity provider yet. Write it into portaliq's app configuration, keyed by the organisation's uuid:

```bash
php occ config:app:set portaliq org_presentation_<organisation uuid> --value='{"oidc":{
  "digid":{"issuer":"https://<digid broker>","clientId":"<client id>",
    "loaClaim":"acr","loaMap":{"urn:etoegang:core:assurance-class:loa3":"substantial","urn:etoegang:core:assurance-class:loa2":"low"}},
  "eherkenning":{"issuer":"https://<eherkenning broker>","clientId":"<client id>",
    "loaClaim":"acr","loaMap":{"urn:etoegang:core:assurance-class:loa3":"substantial","urn:etoegang:core:assurance-class:loa2":"low"}}}}'
php occ config:app:set portaliq oidc_secret_<organisation uuid>_digid --value=<secret> --sensitive
php occ config:app:set portaliq oidc_secret_<organisation uuid>_eherkenning --value=<secret> --sensitive
```

Leave out a mode the organisation does not use. De Wilgenboom and Vaartveld use DigiD; Esdoornveen and the Warmtepompacademie use eHerkenning for companies.

Do not leave out `loaClaim` and `loaMap`. They tell the portal which assurance level the broker reported. Without them every sign-in counts as level low, and a guardian sees none of her children: their records ask for level substantial.

### Portal accounts for pupils, students and participants

Pupils, students and course participants sign in with their Nextcloud account. That works only when they also have a portal account. The load gives them one: Noor Bakker (vo), Milan de Groot and Aylin Demir (mbo) and Tom Verbeek (training). For the demo it also gives Petra Bakker, the trainer at Bakker Techniek BV, a Nextcloud account and a trainer's portal account, so she can approve Milan's hours without hand work. It needs the portal's organisation, so link the portals first and then load the set again. The load prints a line such as `Portal accounts: 1 given, 0 kept, 0 failed.` A second load gives nothing new.

Guardians, trainers and employers get their account through an invitation: `occ learniq:portal:invite-guardian`, `learniq:portal:invite-trainer` and `learniq:portal:invite-employer`. A real trainer signs in with eHerkenning. Her invitation leaves a waiting account that her first eHerkenning sign-in completes, and it sends her a mail, so the instance must be able to send mail.

A new account has a random password. Set one with `occ user:resetpassword` before a story person signs in.

## Timetable import and SWV hand-offs

The timetable is planninq's: integriq reads it from Zermelo, Untis, Xedule or TimeEdit and delivers it to planninq, and learniq reads the lessons from there. The timetable row on the Integrations page shows as available once planninq is installed; learniq reports it once a day and when you save the admin settings.

Under **Administration settings > Learniq > Timetable and SWV exchange**, say which group code in each rostering system is which group in learniq, and name the integriq receiver of your support requests for the SWV (for example `swv-kindkans`). An import uses the map of the system it reads.

Anyone allowed to request an exchange (by default administrators and administration managers) finds **Import a timetable** on the timetable conflicts page. After the delivery, learniq checks the imported lessons for conflicts.

## First-login checklist

After enabling Learniq:

- [ ] Open Learniq from the Nextcloud app menu
- [ ] Answer the two wizard questions: which example data, and what kind of organisation
- [ ] Confirm the dashboard loads without errors

## Troubleshooting

**Learniq shows a blank screen after install**
The JavaScript is not built. Run `npm ci && npm run build` in the `learniq` folder.

**"OpenRegister not found" error**
Install and enable OpenRegister before enabling Learniq. Learniq requires OpenRegister as a dependency.

**Some data is missing after loading example data**
Run `php occ maintenance:repair` once after the loads, then let cron empty its queue.

**Permission error on first open**
Ensure the Nextcloud `www-data` user has write access to the `custom_apps/learniq` directory.

## Upgrading

Learniq follows Nextcloud's standard upgrade path. When a new version is available:

1. The Nextcloud update notification will appear in **Administration > Overview**.
2. Click **Update** next to Learniq, or run `php occ upgrade`.
3. The repair step will migrate any register schema changes automatically.

## Uninstalling

To remove Learniq:

```bash
php /var/www/html/occ app:disable learniq
php /var/www/html/occ app:remove learniq
```

Note: this does not delete data stored in OpenRegister. To remove Learniq data, delete the associated registers in OpenRegister's administration interface.
