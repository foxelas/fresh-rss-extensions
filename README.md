# FreshRSS extensions

Three extensions for [FreshRSS](https://freshrss.org/), used to follow Greek mountaineering and hiking club feeds.

| Extension | What it does | Type |
|---|---|---|
| [Event Dates](#event-dates) | Uses the excursion/event date as the article date and adds "Add to calendar" buttons | system |
| [EOSH Link Fix](#eosh-link-fix) | Fixes the article links of the ΕΟΣ Ηλιούπολης feed | system |
| [Image Proxy](#image-proxy) | Loads article images through a proxy, so they don't break or trigger insecure-content warnings | user |

## Installing

1. Copy the extension folder (for example `xExtension-EventDates/`) into the `extensions/` folder of your FreshRSS install.
   On a hosted install such as PikaPods, upload it over **SFTP** (e.g. with Cyberduck or FileZilla).
2. In FreshRSS, go to **Settings → Extensions** and enable it.
   - **System** extensions can only be enabled by the admin account and apply to every user.
   - **User** extensions are enabled by each user for their own account.

To update an extension, replace its files with the new ones. Nothing else is needed.

---

## Event Dates

`xExtension-EventDates/`

Club feeds announce excursions, but the article date is usually when the post was published or when FreshRSS fetched it, not when the trip happens. This extension finds the **excursion dates** (start and end) in each article and uses them.

### What it does

- **Article date = excursion start date.** New articles are saved with the start date, so FreshRSS shows (and, if you sort by publication date, sorts by) the date of the trip.
- **Keeps both dates.** The end date and the original publish date are stored in hidden tags (`_ev:` and `_pub:`) that are never shown.
- **Date line and calendar buttons** right under the article title:

  > 🥾 **Excursion:** 27/02/2027 – 28/02/2027 · 📰 **Published:** 18/11/2026
  > 📅 Google Calendar · 📥 .ics file

  - **Google Calendar** opens a pre-filled all-day event (title, dates, link, published date, and the start of the text).
  - **.ics file** downloads the same event for Apple Calendar, Outlook and others.
- **Older articles** (received before the extension was enabled) show the excursion date and buttons as well, but keep sorting by their old stored date. To fix that, use **Delete all articles → Update feeds** on a feed whose site still lists all its items.
- Articles with no excursion date (news, announcements) are left as they are.

### Where the dates come from

The extension checks these, in order, and uses the first one it finds:

1. **JSON feeds with explicit dates.** If the article date is a plain date (midnight) and the feed maps an ISO date into *item author* and/or an ISO date-time into *item tags*, those are used as the start, end and publish dates (see [ΕΟΣ Ηλιούπολης setup](#eos-ilioupolis-setup)).
2. **The title**, in any of these forms:
   - numbers: `13/9`, `5-6/9`, `13 -14/2`, `5 – 8/2`, `12-13-14/6/2026`, `21&22/2/2026`, `29-31/5 – 1/6`, `30/10 - 1/11`, `14/2/2027`, `24/1/27`, `28-11-2026`, `29-11-26`, `28.6.2026`
   - Greek month names, with or without accents, capitals or year: `25 Ιανουαρίου 2026`, `19 Απρ 2026`, `14-15 Μαρτίου 2026`, `27 & 28 Σεπτεμβρίου`, `26 Φεβρουαρίου – 15 Μαρτίου 2027`, `ΚΥΡΙΑΚΗ 16 NOEMBRIOY 2025` (Latin look-alike letters are handled too)
3. **The first 400 characters of the text**, but only dates that are not in the past. This avoids news reports getting the date of an event they describe afterwards.

When the year is missing, the extension picks the year (previous, current or next) that puts the date closest to the article's publish date, so `10/1` in a programme published in November becomes January of the next year. Ranges longer than 60 days, or more than about 18 months from the publish date, are ignored.

### Settings

At the top of `extension.php`:

| Constant | Default | Meaning |
|---|---|---|
| `CATEGORIES` | `[]` | Only act on these categories (exact names). Empty means all categories. |
| `SKIP_FEEDS` | `[]` | Feeds to leave alone (exact feed names), e.g. a news feed that gets wrong dates from dates mentioned in passing. |
| `RECEIVED_ONLY_FEEDS` | `['ΠΟΑ', 'ΕΟΣ ΣΠΑΡΤΗΣ']` | Feeds whose sites give no publish date. Their date line says **📥 Received** instead of **📰 Published**. Matches any feed whose name *contains* one of these. |

### ΕΟΣ Ηλιούπολης setup

<a id="eos-ilioupolis-setup"></a>
The `https://eosh.gr/api/trips` JSON feed has `start_date`, `end_date` and `created_at` fields, but FreshRSS's JSON settings have no "end date" or "published date" field. So two unused fields carry them, and the extension reads and then hides them:

| Feed setting (JSON dot notation) | Value | Used as |
|---|---|---|
| item date | `start_date` | excursion start |
| item author | `end_date` | excursion end (one-day trips may leave it empty) |
| item tags | `created_at` | real publish date |

### Limits

- Sites that publish no date per article (ΠΟΑ, ΕΟΣ Σπάρτης) can only show the date FreshRSS received the article as "Received".
- Feeds with no dates at all (for example Utopia Adventures) get no excursion date.
- A news post that mentions a future date in its first lines may get that date. Add such feeds to `SKIP_FEEDS` if this happens too often.
- FreshRSS has a single sort order: either date received or publication date (which is the excursion date for articles this extension handled).
- FreshRSS strips inline styles from article content, so the buttons look like plain links.

### How it works

- `entry_before_insert`: finds the dates when an article arrives, sets the article date to the start date and adds the hidden `_ev:` / `_pub:` tags.
- `entry_before_display`: reads those tags (or works the dates out for older articles), hides the internal tags and adds the date line and buttons above the content.
- While articles are being displayed, FreshRSS is still reading them from the database, so the extension takes feed names from the feed list FreshRSS has already loaded for the sidebar instead of querying the database (which would fail with "Cannot execute queries while other unbuffered queries are active" on MySQL).

---

## EOSH Link Fix

`xExtension-EoshLinkFix/`

The ΕΟΣ Ηλιούπολης JSON feed only gives each trip's `slug` (e.g. `hymettus-ilioupoli-scouts-square-kareas-closter-eosh-refuge`), not a full link. FreshRSS turns it into a link relative to the feed URL, `https://eosh.gr/api/<slug>`, which doesn't exist. FreshRSS has no setting to add a prefix, so this extension rewrites the link when an article arrives:

```
https://eosh.gr/api/<slug>  →  https://eosh.gr/expeditions/<slug>
```

- Only links of exactly that form are changed, so no other feed is affected.
- It only applies to articles received after it is enabled. For existing articles, use **Delete all articles → Update feeds** on the ΕΟΣ Ηλιούπολης feed.
- No settings.

---

## Image Proxy

`xExtension-ImageProxy/`

A third-party extension by Frans de Jonge, from the official [FreshRSS extensions](https://github.com/FreshRSS/Extensions) collection, licensed under the GPL v3 (see [its LICENSE](xExtension-ImageProxy/LICENSE)).

When FreshRSS is served over HTTPS, images that feeds link over plain HTTP are blocked or trigger "insecure content" warnings. This extension rewrites the `src` and `srcset` of every image in an article so it loads through an image proxy (by default [wsrv.nl](https://wsrv.nl)) over HTTPS. Optionally it can proxy HTTPS images too, so your browser never connects to the feed sites directly.

It is configured per user under **Settings → Extensions → Image Proxy → ⚙️**: proxy URL, which schemes to proxy, and how the URL is passed. See [its own README](xExtension-ImageProxy/README.md) for all options and for running your own proxy with Apache or nginx.

---

## License

Event Dates and EOSH Link Fix: MIT, see [LICENSE](LICENSE).
Image Proxy: GPL v3, see [xExtension-ImageProxy/LICENSE](xExtension-ImageProxy/LICENSE).
