<?php

/**
 * Event Dates for FreshRSS
 *
 * - Finds the event/excursion date (start + end) of each article.
 * - New articles: the article date is set to the event start date, so
 *   FreshRSS shows and sorts by it. The original publish date and the
 *   end date are kept in hidden tags (_pub:..., _ev:...).
 * - Older articles: the event date is worked out when they are shown.
 * - Adds a line with both dates and "Google Calendar" / ".ics file"
 *   buttons under every article that has an event date.
 *
 * Where the date is read from, in order:
 *   1. JSON feeds that put an ISO date (2026-11-26) in "item author" use it
 *      as the end date and the article date as the start date
 *      (set up for ΕΟΣ Ηλιούπολης: date = start_date, author = end_date,
 *      tags = created_at).
 *   2. The title: 13/9, 5-6/9, 29-31/5 – 1/6, 14/2/2027, 28-11-2026,
 *      25 Ιανουαρίου 2026, 14-15 Μαρτίου, 26 Φεβρουαρίου – 15 Μαρτίου 2027 ...
 *   3. The first 400 characters of the text (only dates that are not in the past).
 */
class EventDatesExtension extends Minz_Extension {

    /** Only these categories (exact names, not case-sensitive). Empty = all categories. */
    private const CATEGORIES = [];

    /** Feeds to skip entirely (feed names as shown in FreshRSS). */
    private const SKIP_FEEDS = [];

    /**
     * Feeds whose sites give no publish date: the date shown is when FreshRSS
     * received the article, so it is labelled "Received" instead of "Published".
     * Matches any feed whose name contains one of these (not case-sensitive).
     */
    private const RECEIVED_ONLY_FEEDS = ['ΠΟΑ', 'ΕΟΣ ΣΠΑΡΤΗΣ'];

    private const TAG_EVENT = '_ev:';
    private const TAG_PUB   = '_pub:';

    private const MONTHS = [
        1 => 'ιανουαριου|ιανουαριοσ|ιαν', 2 => 'φεβρουαριου|φεβρουαριοσ|φεβ',
        3 => 'μαρτιου|μαρτιοσ|μαρ', 4 => 'απριλιου|απριλιοσ|απρ',
        5 => 'μαιου|μαιοσ|μαη|μαι', 6 => 'ιουνιου|ιουνιοσ|ιουν',
        7 => 'ιουλιου|ιουλιοσ|ιουλ', 8 => 'αυγουστου|αυγουστοσ|αυγ',
        9 => 'σεπτεμβριου|σεπτεμβριοσ|σεπτ|σεπ', 10 => 'οκτωβριου|οκτωβριοσ|οκτ',
        11 => 'νοεμβριου|νοεμβριοσ|νοεμ|νοε', 12 => 'δεκεμβριου|δεκεμβριοσ|δεκ',
    ];

    /** @var array<string,string>|null */
    private static ?array $patterns = null;
    /** @var array<string,int>|null */
    private static ?array $monthMap = null;

    public function init(): void {
        $this->registerHook('entry_before_insert', [$this, 'onInsert']);
        $this->registerHook('entry_before_display', [$this, 'onDisplay']);
    }

    // ------------------------------------------------------------------ hooks

    public function onInsert($entry) {
        if (!self::applies($entry, false)) {
            return $entry;
        }
        [$tags] = self::splitTags($entry);
        foreach ($tags as $t) {
            if (str_starts_with($t, self::TAG_EVENT)) {
                return $entry; // already processed
            }
        }

        $info = self::analyse($entry);
        if ($info['event'] === null) {
            return $entry;
        }
        [$start, $end] = $info['event'];
        $tags = $info['tags'];
        $tags[] = self::TAG_EVENT . $start->format('Ymd') . '-' . $end->format('Ymd');
        $tags[] = self::TAG_PUB . $info['published'];
        $entry->_tags($tags);
        $entry->_date($start->getTimestamp());
        return $entry;
    }

    public function onDisplay($entry) {
        if (!self::applies($entry, true)) {
            return $entry;
        }
        [$tags, $event, $published] = self::splitTags($entry);

        if ($event === null) {
            // Article from before the extension: work it out now
            $info = self::analyse($entry);
            $event = $info['event'];
            $published = $info['published'];
            $tags = $info['tags'];
            if ($event !== null) {
                $entry->_date($event[0]->getTimestamp());
            }
        }
        $entry->_tags($tags); // hides the internal _ev / _pub tags
        if ($event === null) {
            return $entry;
        }

        try {
            $html = self::buttons($entry, $event[0], $event[1], $published);
        } catch (Throwable $ex) {
            $html = '<p style="color:#c00;border:1px solid #c00;padding:.5em;">Event Dates error: '
                . htmlspecialchars($ex->getMessage() . ' (line ' . $ex->getLine() . ')', ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $entry->_content($html . $entry->content());
        return $entry;
    }

    // --------------------------------------------------------------- analysis

    /**
     * [feed name, category name] in lowercase, or null if unknown.
     *
     * While articles are being shown, FreshRSS is still reading them from the
     * database, so asking the database for the feed fails ("unbuffered
     * queries are active"). In that case the feed list FreshRSS has already
     * loaded for the sidebar is used instead.
     */
    private static function feedInfo($entry, bool $displaying): ?array {
        if (!$displaying) {
            $feed = $entry->feed();
            if ($feed === null) {
                return null;
            }
            $cat = $feed->category();
            return [
                mb_strtolower(trim(self::decode($feed->name()))),
                $cat !== null ? mb_strtolower(trim(self::decode($cat->name()))) : '',
            ];
        }

        static $byId = null;
        if ($byId === null) {
            $byId = [];
            $cats = [];
            if (method_exists('FreshRSS_Context', 'categories')) {
                $cats = FreshRSS_Context::categories();
            } elseif (property_exists('FreshRSS_Context', 'categories')) {
                $cats = FreshRSS_Context::$categories;
            }
            foreach ($cats as $cat) {
                foreach ($cat->feeds() as $f) {
                    $byId[(int) $f->id()] = [
                        mb_strtolower(trim(self::decode($f->name()))),
                        mb_strtolower(trim(self::decode($cat->name()))),
                    ];
                }
            }
        }
        $feedId = method_exists($entry, 'feedId') ? (int) $entry->feedId() : (int) $entry->feed(false);
        return $byId[$feedId] ?? null;
    }

    private static function applies($entry, bool $displaying): bool {
        if (self::SKIP_FEEDS === [] && self::CATEGORIES === []) {
            return true; // nothing to check
        }
        $info = self::feedInfo($entry, $displaying);
        if ($info === null) {
            return self::CATEGORIES === [];
        }
        [$feedName, $catName] = $info;
        foreach (self::SKIP_FEEDS as $skip) {
            if (mb_strtolower(trim($skip)) === $feedName) {
                return false;
            }
        }
        if (self::CATEGORIES === []) {
            return true;
        }
        foreach (self::CATEGORIES as $c) {
            if (mb_strtolower(trim($c)) === $catName) {
                return true;
            }
        }
        return false;
    }

    private static function feedNameContains($entry, array $needles): bool {
        $info = self::feedInfo($entry, true);
        if ($info === null) {
            return false;
        }
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($info[0], mb_strtolower($n))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{0:string[],1:?array,2:?int} [visible tags, event [start,end] or null, published ts or null]
     */
    private static function splitTags($entry): array {
        $tags = $entry->tags();
        if (!is_array($tags)) {
            $tags = $tags === '' ? [] : array_map('trim', explode(',', (string) $tags));
        }
        $visible = [];
        $event = null;
        $published = null;
        foreach ($tags as $t) {
            $t = (string) $t;
            if (str_starts_with($t, self::TAG_EVENT)) {
                if (preg_match('/^_ev:(\d{8})-(\d{8})$/', $t, $m)) {
                    $s = DateTimeImmutable::createFromFormat('!Ymd', $m[1]);
                    $e = DateTimeImmutable::createFromFormat('!Ymd', $m[2]);
                    if ($s && $e) {
                        $event = [$s, $e];
                    }
                }
            } elseif (str_starts_with($t, self::TAG_PUB)) {
                $published = (int) substr($t, strlen(self::TAG_PUB));
            } else {
                $visible[] = $t;
            }
        }
        return [$visible, $event, $published];
    }

    /**
     * @return array{event:?array,published:int,tags:string[]}
     */
    private static function analyse($entry): array {
        [$tags] = self::splitTags($entry);
        $published = (int) $entry->date(true);
        $event = null;

        // JSON feeds: ISO datetime in tags = real publish date (e.g. created_at).
        // Its presence also means the article date is the event start date.
        $jsonEventFeed = false;
        foreach ($tags as $i => $t) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $t)) {
                $ts = strtotime($t);
                if ($ts !== false) {
                    $publishedIso = $ts;
                }
                $jsonEventFeed = true;
                unset($tags[$i]);
            }
        }
        $tags = array_values($tags);

        // JSON feeds: ISO date in author = end date, article date = start date.
        // One-day trips may have no end date: then the event is that one day.
        $author = trim((string) $entry->authors(true));
        $isoAuthor = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $author, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        // Guard: a date-only start_date arrives as midnight; a received time
        // (feed not set up yet) does not, so it is not mistaken for the trip date.
        if (($isoAuthor || $jsonEventFeed) && date('H:i:s', $published) === '00:00:00') {
            $start = self::day((int) date('Y', $published), (int) date('n', $published), (int) date('j', $published));
            $end = $isoAuthor ? self::day((int) $m[1], (int) $m[2], (int) $m[3]) : $start;
            if ($start !== null && $end !== null) {
                $event = [$start, $end < $start ? $start : $end];
            }
            if ($isoAuthor) {
                $entry->_authors('');
            }
        }
        if (isset($publishedIso)) {
            $published = $publishedIso;
        }

        if ($event === null) {
            $ref = self::day((int) date('Y', $published), (int) date('n', $published), (int) date('j', $published));
            $event = self::find(self::decode($entry->title()), $ref);
            if ($event === null) {
                $text = mb_substr(self::plainText($entry->content()), 0, 400);
                $found = self::find($text, $ref);
                if ($found !== null && $found[1]->getTimestamp() >= $ref->getTimestamp() - 3 * 86400) {
                    $event = $found;
                }
            }
        }

        return ['event' => $event, 'published' => $published, 'tags' => $tags];
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable}|null [start, end] (end inclusive) */
    private static function find(string $text, DateTimeImmutable $ref): ?array {
        $t = self::norm($text);
        $best = null;
        $bestKey = null;
        foreach (self::patterns() as $kind => $rx) {
            if (!preg_match_all($rx, $t, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($all as $m) {
                $g = [];
                for ($i = 1; $i <= 6; $i++) {
                    $g[] = (isset($m[$i]) && $m[$i][1] !== -1) ? $m[$i][0] : '';
                }
                $r = self::build($kind, $g, $ref);
                if ($r !== null) {
                    $key = [$m[0][1], -strlen($m[0][0])];
                    if ($bestKey === null || $key < $bestKey) {
                        $bestKey = $key;
                        $best = $r;
                    }
                    break;
                }
            }
        }
        return $best;
    }

    private static function build(string $kind, array $g, DateTimeImmutable $ref): ?array {
        $mm = self::monthMap();
        $y = null;
        switch ($kind) {
            case 'gr2': $d1 = (int) $g[0]; $m1 = $mm[$g[1]] ?? 0; $d2 = (int) $g[2]; $m2 = $mm[$g[3]] ?? 0; $y = self::yr($g[4]); break;
            case 'gr1': $d1 = (int) $g[0]; $d2 = (int) $g[1]; $m2 = $mm[$g[2]] ?? 0; $m1 = $m2; $y = self::yr($g[3]); break;
            case 'gr0': $d1 = (int) $g[0]; $m1 = $mm[$g[1]] ?? 0; $y = self::yr($g[2]); $d2 = $d1; $m2 = $m1; break;
            case 'n3':  $d1 = (int) $g[0]; $m1 = (int) $g[1]; $d2 = (int) $g[2]; $m2 = (int) $g[3]; $y = self::yr($g[4]); break;
            case 'n2':  $d1 = (int) $g[0]; $m1 = (int) $g[1]; $d2 = (int) $g[3]; $m2 = (int) $g[4]; $y = self::yr($g[5]) ?? self::yr($g[2]); break;
            case 'n1':  $d1 = (int) $g[0]; $d2 = (int) $g[1]; $m2 = (int) $g[2]; $m1 = $m2; $y = self::yr($g[3]); break;
            default:    $d1 = (int) $g[0]; $m1 = (int) $g[1]; $y = self::yr($g[2]); $d2 = $d1; $m2 = $m1; break; // n0, h0
        }
        if ($m1 < 1 || $m1 > 12 || $m2 < 1 || $m2 > 12) {
            return null;
        }
        $end = self::closest($d2, $m2, $y, $ref);
        if ($end === null) {
            return null;
        }
        $endYear = (int) $end->format('Y');
        $start = self::closest($d1, $m1, $endYear, $ref);
        if ($start !== null && $start > $end) {
            $start = self::closest($d1, $m1, $endYear - 1, $ref);
        }
        if ($start === null) {
            return null;
        }
        $span = ($end->getTimestamp() - $start->getTimestamp()) / 86400;
        if ($span > 60 || abs($start->getTimestamp() - $ref->getTimestamp()) / 86400 > 550) {
            return null;
        }
        return [$start, $end];
    }

    /** The date with this day/month in year $y, or (no year) in the year closest to $ref. */
    private static function closest(int $d, int $m, ?int $y, DateTimeImmutable $ref): ?DateTimeImmutable {
        $refYear = (int) $ref->format('Y');
        $years = $y !== null ? [$y] : [$refYear - 1, $refYear, $refYear + 1];
        $best = null;
        foreach ($years as $yy) {
            $date = self::day($yy, $m, $d);
            if ($date === null) {
                continue;
            }
            if ($best === null || abs($date->getTimestamp() - $ref->getTimestamp()) < abs($best->getTimestamp() - $ref->getTimestamp())) {
                $best = $date;
            }
        }
        return $best;
    }

    private static function day(int $y, int $m, int $d): ?DateTimeImmutable {
        if (!checkdate($m, $d, $y)) {
            return null;
        }
        return (new DateTimeImmutable())->setDate($y, $m, $d)->setTime(0, 0);
    }

    private static function yr(string $y): ?int {
        if ($y === '') {
            return null;
        }
        $n = (int) $y;
        return $n < 100 ? $n + 2000 : $n;
    }

    private static function patterns(): array {
        if (self::$patterns !== null) {
            return self::$patterns;
        }
        $mon = '(' . implode('|', self::MONTHS) . ')\.?(?![α-ωa-z])';
        $sep = '\s*(?:-|–|—|&|και)\s*';
        $y   = '(?:\s*\/\s*(\d{2,4}))?';
        return self::$patterns = [
            // 26 Φεβρουαρίου – 15 Μαρτίου 2027
            'gr2' => '/(?<!\d)(\d{1,2})\s+' . $mon . '\s*[-–—]\s*(\d{1,2})\s+' . $mon . '(?:\s+(\d{4}))?/u',
            // 14-15 Μαρτίου 2026, 18-19-20 Σεπτεμβρίου, 27 & 28 Σεπτεμβρίου
            'gr1' => '/(?<!\d)(\d{1,2})(?:' . $sep . '\d{1,2})*' . $sep . '(\d{1,2})\s+' . $mon . '(?:\s+(\d{4}))?/u',
            // 25 Ιανουαρίου 2026, 19 Απρ 2026
            'gr0' => '/(?<!\d)(\d{1,2})\s+' . $mon . '(?:\s+(\d{4}))?/u',
            // 29-31/5 – 1/6, 30-31/10 & 1/11
            'n3'  => '/(?<![\d\/])(\d{1,2})(?:' . $sep . '\d{1,2})*\s*\/\s*(\d{1,2})' . $sep . '(\d{1,2})\s*\/\s*(\d{1,2})' . $y . '(?![\d\/])/u',
            // 30/10 - 1/11, 06/10 – 14/10/2026
            'n2'  => '/(?<![\d\/])(\d{1,2})\s*\/\s*(\d{1,2})' . $y . '\s*[-–—]\s*(\d{1,2})\s*\/\s*(\d{1,2})' . $y . '(?![\d\/])/u',
            // 5-6/9, 13-19/4/26, 12-13-14/6/2026, 21&22/2/2026
            'n1'  => '/(?<![\d\/])(\d{1,2})(?:' . $sep . '\d{1,2})*' . $sep . '(\d{1,2})\s*\/\s*(\d{1,2})' . $y . '(?![\d\/])/u',
            // 13/9, 14/2/2027, 24/1/27
            'n0'  => '/(?<![\d\/])(\d{1,2})\s*\/\s*(\d{1,2})' . $y . '(?![\d\/])/u',
            // 28-11-2026, 29-11-26, 28.6.2026
            'h0'  => '/(?<![\d\/.\-])(\d{1,2})[-.](\d{1,2})[-.](\d{4}|\d{2})(?![\d\/.\-])/u',
        ];
    }

    private static function monthMap(): array {
        if (self::$monthMap === null) {
            self::$monthMap = [];
            foreach (self::MONTHS as $n => $alts) {
                foreach (explode('|', $alts) as $a) {
                    self::$monthMap[$a] = $n;
                }
            }
        }
        return self::$monthMap;
    }

    /** Lowercase, no accents, Latin look-alike letters turned into Greek (NOEMBRIOY -> νοεμβριου). */
    private static function norm(string $s): string {
        static $map = [
            'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
            'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ',
            'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω',
            'Ϊ' => 'Ι', 'Ϋ' => 'Υ',
            'A' => 'α', 'B' => 'β', 'E' => 'ε', 'H' => 'η', 'I' => 'ι', 'K' => 'κ', 'M' => 'μ',
            'N' => 'ν', 'O' => 'ο', 'P' => 'ρ', 'R' => 'ρ', 'T' => 'τ', 'X' => 'χ', 'Y' => 'υ', 'Z' => 'ζ',
            'a' => 'α', 'e' => 'ε', 'i' => 'ι', 'k' => 'κ', 'o' => 'ο', 'p' => 'ρ', 't' => 'τ',
            'x' => 'χ', 'y' => 'υ', 'v' => 'ν', 'u' => 'υ',
        ];
        $s = mb_strtolower(strtr($s, $map), 'UTF-8');
        return str_replace('ς', 'σ', $s);
    }

    // ---------------------------------------------------------------- output

    private static function buttons($entry, DateTimeImmutable $start, DateTimeImmutable $end, ?int $published): string {
        $title = self::decode($entry->title());
        $url   = self::decode($entry->link(true));
        $text  = self::plainText($entry->content());
        $endExclusive = $end->modify('+1 day');

        $eventLabel = $start->format('d/m/Y') . ($end > $start ? ' – ' . $end->format('d/m/Y') : '');
        $pubLabel = $published ? date('d/m/Y', $published) : '?';
        $receivedOnly = self::feedNameContains($entry, self::RECEIVED_ONLY_FEEDS);
        $pubWord = $receivedOnly ? 'Received' : 'Published';
        $pubIcon = $receivedOnly ? '📥' : '📰';

        $header = ($url !== '' ? $url . "\n\n" : '')
            . 'Excursion: ' . $eventLabel . "\n"
            . $pubWord . ': ' . $pubLabel;

        $google = 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action'  => 'TEMPLATE',
            'text'    => $title,
            'dates'   => $start->format('Ymd') . '/' . $endExclusive->format('Ymd'),
            'details' => $header . "\n\n" . self::truncate($text, 700),
        ], '', '&', PHP_QUERY_RFC3986);

        $ics = self::ics($title, $url, $header . "\n\n" . self::truncate($text, 3000), $start, $endExclusive);
        $icsHref = 'data:text/calendar;charset=utf-8,' . rawurlencode($ics);
        $icsName = 'excursion-' . $start->format('Y-m-d') . '.ics';

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $btn = 'display:inline-block;margin:0 .5em .5em 0;padding:.4em .8em;border:1px solid #888;border-radius:4px;text-decoration:none;';

        return '<div class="event-dates" style="margin-bottom:1.5em;padding-bottom:.75em;border-bottom:1px solid #ccc;">'
            . '<p style="margin:0 0 .6em 0;">🥾 <strong>Excursion:</strong> ' . $e($eventLabel)
            . ' &nbsp;·&nbsp; ' . $pubIcon . ' <strong>' . $pubWord . ':</strong> ' . $e($pubLabel) . '</p>'
            . '<a href="' . $e($google) . '" target="_blank" rel="noopener noreferrer" style="' . $btn . '">📅 Google Calendar</a>'
            . ' &nbsp;·&nbsp; '
            . '<a href="' . $e($icsHref) . '" download="' . $e($icsName) . '" style="' . $btn . '">📥 .ics file</a>'
            . '</div>';
    }

    private static function ics(string $title, string $url, string $description, DateTimeImmutable $start, DateTimeImmutable $endExclusive): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//FreshRSS//Event Dates//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . sha1($url . $title) . '@freshrss-event-dates',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:' . $start->format('Ymd'),
            'DTEND;VALUE=DATE:' . $endExclusive->format('Ymd'),
            'SUMMARY:' . self::icsEscape($title),
            'DESCRIPTION:' . self::icsEscape($description),
        ];
        if ($url !== '') {
            $lines[] = 'URL:' . $url;
        }
        array_push($lines, 'END:VEVENT', 'END:VCALENDAR');
        return implode("\r\n", array_map(static fn(string $l): string => self::icsFold($l), $lines)) . "\r\n";
    }

    private static function icsEscape(string $s): string {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $s);
    }

    /** Folds lines to max 75 bytes without splitting UTF-8 characters. */
    private static function icsFold(string $line): string {
        $out = '';
        $limit = 75;
        while (strlen($line) > $limit) {
            $chunk = mb_strcut($line, 0, $limit, 'UTF-8');
            $out .= $chunk . "\r\n ";
            $line = substr($line, strlen($chunk));
            $limit = 74;
        }
        return $out . $line;
    }

    private static function plainText(string $html): string {
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;
        $text = self::decode(strip_tags($html));
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;
        return trim($text);
    }

    private static function decode(string $s): string {
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function truncate(string $s, int $max): string {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
    }
}
