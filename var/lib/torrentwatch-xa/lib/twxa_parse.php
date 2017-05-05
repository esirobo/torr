<?php

// functions for parsing torrent titles
// feeds.php calls this file

$seps = '\s\.\_'; // separator chars: - and () were formerly also separators but caused problems; we need - for some Season and Episode notations
// load matchTitle function files
require_once("/var/lib/torrentwatch-xa/lib/twxa_parse_match.php");
require_once("/var/lib/torrentwatch-xa/lib/twxa_parse_match0.php");
require_once("/var/lib/torrentwatch-xa/lib/twxa_parse_match1.php");
require_once("/var/lib/torrentwatch-xa/lib/twxa_parse_match2.php");
require_once("/var/lib/torrentwatch-xa/lib/twxa_parse_match3.php");
require_once("/var/lib/torrentwatch-xa/lib/twxa_parse_match4.php");

function collapseExtraSeparators($ti) {
    $ti = str_replace("  ", " ", $ti);
    $ti = str_replace(" .", ".", $ti);
    $ti = str_replace(". ", ".", $ti);
    $ti = str_replace("..", ".", $ti);
    $ti = str_replace("__", "_", $ti);
    // trim beginning and ending spaces, periods, and minuses
    $ti = trim($ti, ".- \t\n\r\0\x0B");
    return $ti;
}

function collapseExtraMinuses($ti) {
    $ti = str_replace("- -", "-", $ti);
    $ti = str_replace("--", "-", $ti);
    return $ti;
}

function removeEmptyParens($ti) {
    // remove empty parentheses
    $ti = str_replace("( ", "(", $ti);
    $ti = str_replace(" )", ")", $ti);
    $ti = str_replace("(-)", "", $ti);
    $ti = str_replace("(.)", "", $ti);
    $ti = str_replace("( )", "", $ti);
    $ti = str_replace("()", "", $ti);
    return $ti;
}

function sanitizeTitle($ti) {
    // cleans title of symbols, aiming to get the title down to just alphanumerics and reserved separators
    // we sanitize the title to make it easier to use Favorites and match episodes
    // remove soft hyphens
    $ti = str_replace("\xC2\xAD", "", $ti);
    // replace every tilde with a minus
    $ti = str_replace("~", "-", $ti);
    // replace brackets, etc., with space; keep parentheses, periods, underscores, and minus
    $ti = str_replace('[', ' ', $ti);
    $ti = str_replace(']', ' ', $ti);
    $ti = str_replace('{', ' ', $ti);
    $ti = str_replace('}', ' ', $ti);
    $ti = str_replace('<', ' ', $ti);
    $ti = str_replace('>', ' ', $ti);
    $ti = str_replace(',', ' ', $ti);
    $ti = str_replace('_', ' ', $ti);
    $ti = str_replace('/', ' ', $ti);
    // IMPORTANT: reduce multiple reserved separators down to one separator
    return collapseExtraSeparators($ti);
}

function normalizeCodecs($ti, $seps = '\s\.\_') {
    $ti = preg_replace("/([XxHh])[$seps\-]+(264|265)/", "$1$2", $ti); // note the separator chars PLUS - char
    $ti = preg_replace("/(\d{1,3})[$seps\-]?bits?/i", "$1bit", $ti); // normalize ## bit
    $ti = preg_replace("/FLAC[$seps\-]+2(\.0)?/i", 'FLAC2', $ti);
    $ti = preg_replace("/AAC[$seps\-]+2(\.0)?/i", 'AAC2', $ti);
    return $ti;
}

function validateYYYYMMDD($date) {
    $YYYY = substr($date, 0, 4);
    $MM = substr($date, 4, 2);
    $DD = substr($date, 6, 2);
    return checkdate($MM, $DD, $YYYY);
}

function simplifyTitle($ti) {
    // combines all the title processing functions
    $ti = sanitizeTitle($ti);

    // MUST normalize these codecs/qualities now so that users get trained to use normalized versions
    $ti = normalizeCodecs($ti);

    // detect and strip out 7 or 8-character checksums
    $mat = [];
    if (preg_match("/([0-9a-f])[0-9a-f]{6,7}/i", $ti, $mat)) {
        // only handle first one--not likely to have more than one checksum in any title
        $wholeMatch = $mat[0];
        $firstChar = $mat[1];
        if (preg_match("/\D/", $wholeMatch)) {
            // any non-digit means it's a checksum
            $ti = str_replace($wholeMatch, "", $ti);
        } else if ($firstChar > 2) {
            // if first digit is not 0, 1, or 2, it's likely not a date
            $ti = str_replace($wholeMatch, "", $ti);
        } else {
            // remove 8-digit checksums that look like they might be dates
            if (!validateYYYYMMDD($wholeMatch)) {
                $ti = str_replace($wholeMatch, "", $ti);
            }
        }
    }
    // run collapse due to possibility of checksum removal leaving back-to-back separators
    return collapseExtraSeparators($ti);
}

function detectResolution($ti, $seps = '\s\.\_') {
    $wByHRegEx = "/(\d{3,4})[$seps]*[xX][$seps]*((\d{3,4})[iIpP]?)/";
    $hRegEx = "/\b(\d{3,4})[iIpP]\b/"; // added \b to end to block YUV444P10
    $bDHRegEx = "/\bBD(\d{3,4})([iIpP]?)\b/"; //TODO handle BD1280x720p
    $resolution = "";
    $matchedResolution = "";
    $verticalLines = "";
    $detQualities = [];
    $matches = [];

    if (preg_match($bDHRegEx, $ti, $matches)) {
        // standalone resolutions in BD### format
        // shouldn't be more than one resolution in title
        if (
                $matches[1] == 576 ||
                $matches[1] == 720 ||
                $matches[1] == 1076 || // some people are forcing 1920x1076
                $matches[1] == 1080 ||
                $matches[1] == 1200
        ) {
            $matchedResolution = $matches[0];
            $verticalLines = $matches[1];
            if ($matches[2] === "") {
                $resolution = $matches[1] . "p";
            } else {
                $resolution = strtolower($matches[1] . $matches[2]);
            }
        }
    } else if (preg_match($hRegEx, $ti, $matches)) {
        // standalone resolutions in ###p or ###i format
        // shouldn't be more than one resolution in title
        $matchedResolution = $matches[0];
        $resolution = strtolower($matchedResolution);
        $verticalLines = $matches[1];
    } else if (preg_match($wByHRegEx, $ti, $matches)) {
        // search arbitrarily for #### x #### (might also be Season x Episode or YYYY x MMDD)
        // check aspect ratios
        if (
                $matches[1] * 9 == $matches[3] * 16 || // 16:9 aspect ratio
                $matches[1] * 0.75 == $matches[3] || // 4:3 aspect ratio
                $matches[1] * 5 == $matches[3] * 8 || // 16:10 aspect ratio
                $matches[1] * 2 == $matches[3] * 3 || // 3:2 aspect ratio
                $matches[1] * 0.8 == $matches[3] || // 5:4 aspect ratio
                $matches[1] * 10 == $matches[3] * 19 || // 19:10 4K aspect ratio
                $matches[1] * 135 == $matches[3] * 256 || // 256:135 4K aspect ratio
                $matches[1] * 3 == $matches[3] * 7 || // 21:9 4K aspect ratio
                $matches[3] == 576 ||
                $matches[3] == 720 ||
                $matches[3] == 1040 || // some people are forcing 1920x1040
                $matches[3] == 1076 || // some people are forcing 1920x1076
                $matches[3] == 1080 ||
                $matches[3] == 1200 ||
                ($matches[1] == 720 && ($matches[3] == 406 || $matches[3] == 544)) || // some people are forcing 720x406 or 720x544
                ($matches[3] == 480 && $matches[1] >= 848 && $matches[1] <= 864) || // some people are forcing 848x480p or 852x480p
                ($matches[1] == 704 && $matches[3] == 400) || // some people are forcing 704x400
                ($matches[1] == 744 && $matches[3] == 418) // some people are forcing 744x418
        ) {
            $matchedResolution = $matches[0];
            $resolution = strtolower($matches[2]);
            $verticalLines = $matches[3];
            if ($resolution == $verticalLines) {
                $resolution .= 'p'; // default to p if no i or p is specified
            }
        }
    }
    $ti = str_replace($matchedResolution, "", $ti);
    if ($verticalLines == 720 || $verticalLines == 1080) {
        $detQualities = ["HD", "HDTV"];
    } else if ($verticalLines == 576) {
        $detQualities = ["ED", "EDTV"];
        $ti = preg_replace("/SD(TV)?/i", "", $ti); // remove SD also (ED will be removed by detectQualities())
    } else if ($verticalLines == 480) {
        $detQualities = ["SD", "SDTV"];
    }
    if ($resolution !== "") {
        $detQualities[] = $resolution;
    }
    return [
        'parsedTitle' => collapseExtraSeparators($ti),
        'detectedQualities' => $detQualities
    ];
}

function detectQualities($ti, $seps = '\s\.\_') {
    $qualitiesFromResolution = detectResolution($ti, $seps);
    // more quality matches and prepend them to detectedQualities
    $ti = $qualitiesFromResolution['parsedTitle'];
    $detQualities = $qualitiesFromResolution['detectedQualities'];
    $qualityList = [
        'BDRip',
        'BRRip',
        'BluRay',
        'BD',
        'HR.HDTV',
        'HDTV',
        'HDTVRip',
        'DSRIP',
        'DVB',
        'DVBRip',
        'TVRip',
        'TVCap',
        'TVDub',
        'TV-Dub',
        'HR.PDTV',
        'PDTV',
        'SatRip',
        'WebRip',
        'DVDRip',
        'DVDR',
        'DVDScr',
        'DVD9',
        'DVD5',
        'XviDVD',
        // DVD regions
        'DVD R0',
        'DVD R1',
        'DVD R2',
        'DVD R3',
        'DVD R4',
        'DVD R5',
        'DVD R6',
        // END DVD regions
        'DVD',
        'DSR',
        'SVCD',
        'WEB-DL',
        'WEB.DL',
        'HTML5',
        'iTunes',
        // codecs--could be high or low quality, who knows?
        'XviD',
        'x264',
        'h264',
        'x265',
        'h265',
        'Hi10P',
        'Hi10',
        'HEVC2',
        'HEVC',
        'Ma10p',
        '24bit',
        '10bit',
        '8bit',
        'AVC',
        'AVI',
        'MP4',
        'MKV',
        'BT.709',
        'BT.601',
        // colorspaces
        'YUV420p10',
        'YUV444p10',
        'YUV440p12',
        'GBRP10',
        // analog color formats
        'NTSC',
        'PAL',
        'SECAM',
        // text encodings
        'BIG5',
        'BIG5+GB',
        'BIG5_GB',
        'GB', // might match unintended abbreviations
        // framespeeds
        '60fps',
        '30fps',
        '24fps',
        // typically low quality
        'VHSRip',
        'TELESYNC'
    ];
    foreach ($qualityList as $qualityListItem) {
        if (stripos($ti, $qualityListItem) !== false) {
            $detQualities[] = $qualityListItem;
            $ti = str_ireplace($qualityListItem, "", $ti);
        }
    }
    return [
        'parsedTitle' => collapseExtraSeparators($ti),
        'detectedQualities' => $detQualities,
    ];
}

function detectAudioCodecs($ti) {
    $detAudioCodecs = [];
    $audioCodecList = [ // watch the order!
        'EAC3',
        'AC3',
        'AACx2',
        'AAC2',
        'AAC',
        'FLACx2',
        'FLAC2',
        'FLAC',
        '320Kbps',
        '320kbps',
        '320K',
        'MP3',
        'M4A',
        '5.1ch',
        '5.1',
        '2ch'
    ];
    foreach ($audioCodecList as $audioCodecListItem) {
        if (stripos($ti, $audioCodecListItem) !== false) {
            $detAudioCodecs[] = $audioCodecListItem;
            // cascade down through, removing immediately-surrouding dashes
            $ti = str_ireplace($audioCodecList, "", $ti);
        }
    }
    return [
        'parsedTitle' => collapseExtraSeparators($ti),
        'detectedAudioCodecs' => $detAudioCodecs
    ];
}

function detectNumericCrew($ti, $seps = '\s\.\_') {
    // detect crew name with numerals in title and remove it
    // assume crew name is always at the beginning of the title and is often in parentheses or brackets
    $origTitle = $ti;
    $rmCrewName = "";
    $mat = [];
    $crewNameList = [
        "(C72)",
        "&#40;C72&#41;",
        "(C88)",
        "&#40;C88&#41;",
        "(C91)",
        "&#40;C91&#41;",
        "Al3asq",
        "F4A-MDS",
        "blad761",
        "bonkai77"
    ];
    foreach ($crewNameList as $crewName) {
        $quotedCrewName = preg_quote($crewName);
        if (preg_match("/^" . $quotedCrewName . "[" . $seps . "]*/", $ti, $mat)) { // can't use strpos because we need $mat
            // found it at the beginning, now remove it to be re-added later
            $ti = preg_replace("/" . $quotedCrewName . "[" . $seps . "]*/", "", $ti);
            $rmCrewName = $mat[0];
            break;
        }
    }
    return [
        'rmCrewName' => $rmCrewName,
        'parsedTitle' => $ti
    ];
}

function detectMatch($ti) {
    $episode_guess = "";

    // detect qualities
    $detectQualitiesOutput = detectQualities(simplifyTitle($ti));
    $detQualitiesJoined = implode(' ', $detectQualitiesOutput['detectedQualities']);
    // don't use count() on arrays because it returns 1 if not countable; it is enough to know if any quality was detected
    if (strlen($detQualitiesJoined) > 0) {
        $wereQualitiesDetected = true;
    } else {
        $wereQualitiesDetected = false;
    }

    //TODO detect video-related words like Sub and Dub
    // strip out audio codecs
    $detectAudioCodecsOutput = detectAudioCodecs($detectQualitiesOutput['parsedTitle']);

    // after removing Qualities and Audio Codecs, there may be ( ) or () left behind
    $detectAudioCodecsOutput['parsedTitle'] = removeEmptyParens(collapseExtraMinuses($detectAudioCodecsOutput['parsedTitle']));

    // strip the crew name
    $detectNumericCrewOutput = detectNumericCrew($detectAudioCodecsOutput['parsedTitle']);

    // detect episode
    $detectItemOutput = detectItem($detectNumericCrewOutput['parsedTitle'], $wereQualitiesDetected);
    $detectItemOutput['favTitle'] = removeEmptyParens($detectItemOutput['favTitle']);
    $seasonBatchEnd = $detectItemOutput['seasBatEnd'];
    $seasonBatchStart = $detectItemOutput['seasBatStart'];
    $episodeBatchEnd = $detectItemOutput['episBatEnd'];
    $episodeBatchStart = $detectItemOutput['episBatStart'];

    // parse episode output into human-friendly notation
    // our numbering style is 1x2v2-2x3v3
    if ($seasonBatchEnd > -1) {
        // found a ending season, probably detected other three values too
        if ($seasonBatchEnd == $seasonBatchStart) {
            // within one season
            if ($episodeBatchEnd == $episodeBatchStart && $episodeBatchEnd > -1) {
                // single episode
                if ($seasonBatchEnd == 0) {
                    // date notation
                    $episode_guess = $episodeBatchEnd;
                } else {
                    $episode_guess = $seasonBatchEnd . 'x' . $episodeBatchEnd;
                }
                if ($detectItemOutput['itemVersion'] > 1) {
                    $episode_guess .= "v" . $detectItemOutput['itemVersion'];
                }
            } else if ($episodeBatchEnd > $episodeBatchStart && $episodeBatchStart > -1) {
                // batch of episodes within one season
                if ($seasonBatchEnd == 0) {
                    // date notation
                    $episode_guess = $episodeBatchStart . '-' . $episodeBatchEnd;
                } else {
                    $episode_guess = $seasonBatchStart . 'x' . $episodeBatchStart . '-' . $seasonBatchStart . 'x' . $episodeBatchEnd;
                }
            } else if ($episodeBatchEnd == "") {
                // assume full season
                $episode_guess = $seasonBatchEnd . 'xFULL';
            } else {
                //TODO not sure of what exceptions there might be to the above
            }
        } else if ($seasonBatchEnd > $seasonBatchStart) {
            // batch spans multiple seasons, treat EpisodeStart as paired with SeasonStart and EpisodeEnd as paired with SeasonEnd
            if ($episodeBatchEnd == "") {
                $episode_guess = $seasonBatchStart . 'xFULL-' . $seasonBatchEnd . 'xFULL';
            } else {
                $episode_guess = $seasonBatchStart . 'x' . $episodeBatchStart . '-' . $seasonBatchEnd . 'x' . $episodeBatchEnd;
            }
        }
    } else {
        $episode_guess = "noShow";
    }
    //TODO handle PV and other numberSequence values
    //TODO add itemVersion handling to batches such as 1x03v2-1x05v2
    // add the removed crew name back if one was removed
    $favTitle = collapseExtraSeparators($detectItemOutput['favTitle']);
    if ($detectNumericCrewOutput['rmCrewName'] !== "") {
        $favTitle = $detectNumericCrewOutput['rmCrewName'] . $favTitle;
    }

    //TODO strip off remaining unmatched codecs from favTitle if at the end and in parentheses

    return [
        'title' => collapseExtraSeparators($detectAudioCodecsOutput['parsedTitle']),
        'favTitle' => $favTitle,
        'qualities' => $detQualitiesJoined,
        'episode' => $episode_guess,
        'seasBatEnd' => $detectItemOutput['seasBatEnd'],
        'seasBatStart' => $detectItemOutput['seasBatStart'],
        'episBatEnd' => $detectItemOutput['episBatEnd'],
        'episBatStart' => $detectItemOutput['episBatStart'],
        'isVideo' => $wereQualitiesDetected, //TODO replace this with mediaType
        'mediaType' => $detectItemOutput['mediaType'],
        'itemVersion' => $detectItemOutput['itemVersion'],
        'numberSequence' => $detectItemOutput['numberSequence'],
        'debugMatch' => $detectItemOutput['debugMatch']
    ];
}

function guess_feed_type($feedurl) {
    $response = check_for_cookies($feedurl);
    if (isset($response)) {
        $feedurl = $response['url'];
    }
    $get = curl_init();
    $getOptions[CURLOPT_URL] = $feedurl;
    get_curl_defaults($getOptions);
    curl_setopt_array($get, $getOptions);
    $content = explode("\n", curl_exec($get));
    curl_close($get);
    // Should be on the second line, but test up to the first 5 in case of doctype, etc.
    for ($i = 0; $i < count($content) && $i < 5; $i++) {
        twxa_debug("Head of feed from URL: " . $content[$i] . "\n", 2);
        if (strpos($content[$i], '<feed xml') !== false) {
            twxa_debug("Feed $feedurl appears to be an Atom feed\n", 2);
            return 'Atom';
        } else if (strpos($content[$i], '<rss') !== false) {
            twxa_debug("Feed $feedurl appears to be an RSS feed\n", 2);
            return 'RSS';
        }
    }
    twxa_debug("Cannot figure out feed type of $feedurl\n", 0);
    return "Unknown"; // was set to "RSS" as default, but this seemed to cause errors in add_feed()
}

function guess_atom_torrent($summary) {
    $wc = '[\/\:\w\.\+\?\&\=\%\;]+';
    // Detects: A HREF=\"http://someplace/with/torrent/in/the/name\"
    $regs = [];
    if (preg_match('/A HREF=\\\"(http' . $wc . 'torrent' . $wc . ')\\\"/', $summary, $regs)) {
        twxa_debug("guess_atom_torrent: $regs[1]\n", 2);
        return $regs[1];
    } else {
        twxa_debug("guess_atom_torrent: failed\n", 2); //TODO return and fix this function
    }
    return false;
}

function detectItem($ti, $wereQualitiesDetected = false, $seps = '\s\.\_') {
    // our numbering style is 1x2v2-2x3v3
    // $wereQualitiesDetected is a param because some manga use "Vol. ##" notation
    // $medTyp state table
    // 0 = Unknown
    // 1 = Video
    // 2 = Audio
    // 4 = Print media
    // $numSeq allows for parallel numbering sequences
    // like Movie 1, Movie 2, Movie 3 alongside Episode 1, Episode 2, Episode 3
    // 0 = Unknown
    // 1 = Video: Season x Episode or FULL, Print Media: Volume x Chapter or FULL, Audio: Season x Episode or FULL
    // 2 = Video: Date, Print Media: Date, Audio: Date (all these get Season = 0)
    // 4 = Video: Season x Volume or Part, Print Media: N/A, Audio: N/A
    // 8 = Video: Preview, Print Media: N/A, Audio: Opening songs
    // 16 = Video: Special, Print Media: N/A, Audio: Ending songs
    // 32 = Video: OVA episode sequence, Print Media: N/A, Audio: Character songs
    // 64 = Video: Movie sequence (Season = 0), Print Media: N/A, Audio: OST
    // 128 = Video: Volume x Disc sequence, Print Media: N/A, Audio: N/A
    // IMPORTANT NOTES:
    // treat anime notation as Season 1
    // treat date-based episodes as Season 0 EXCEPT...
    // ...when YYYY-##, use year as the Season and ## as the Episode
    // because of PHP left-to-right matching order, (Season|Seas|Se|S) works but (S|Se|Seas|Season) will match S and move on
    //TODO handle PROPER and REPACK episodes as version 99 if not specified
    //TODO decode HTML and URL encoded characters to reduce number of extraneous numerals
    $ti = html_entity_decode($ti, ENT_QUOTES);

    // bucket the matches of all numbers of different lengths
    $matNums = [];
    preg_match_all("/(\d+)/u", $ti, $matNums, \PREG_SET_ORDER); // can't initialize $matNums here due to isset tests later
    // is there at least one number? can't have an episode otherwise (except in case of PV preview episode)
    $numbersDetected = count($matNums);
    if (isset($matNums[0])) {
        switch ($numbersDetected) {
            case 8:
            case 7:
            case 6:
                $result = matchTitle6_($ti, $seps);
                if ($result['matFnd'] !== "6_") {
                    if ($numbersDetected !== 6) {
                        $result['matFnd'] = $numbersDetected . "_ (" . $result['matFnd'] . ")";
                    }
                    break;
                }
            case 5:
                $result = matchTitle5_($ti, $seps);
                if ($result['matFnd'] !== "5_") {
                    if ($numbersDetected !== 5) {
                        $result['matFnd'] = $numbersDetected . "_ (" . $result['matFnd'] . ")";
                    }
                    break;
                }
            case 4:
                $result = matchTitle4_($ti, $seps);
                if ($result['matFnd'] !== "4_") {
                    if ($numbersDetected !== 4) {
                        $result['matFnd'] = $numbersDetected . "_ (" . $result['matFnd'] . ")";
                    }
                    break;
                }
            case 3:
                $result = matchTitle3_($ti, $seps);
                if ($result['matFnd'] !== "3_") {
                    if ($numbersDetected !== 3) {
                        $result['matFnd'] = $numbersDetected . "_ (" . $result['matFnd'] . ")";
                    }
                    break;
                }
            case 2:
                $result = matchTitle2_($ti, $seps, $wereQualitiesDetected);
                if ($result['matFnd'] !== "2_") {
                    if ($numbersDetected !== 2) {
                        $result['matFnd'] = $numbersDetected . "_ (" . $result['matFnd'] . ")";
                    }
                    break;
                }
            case 1:
                $result = matchTitle1_($ti, $seps, $wereQualitiesDetected);
                if ($result['matFnd'] !== "1_") {
                    if ($numbersDetected !== 1) {
                        $result['matFnd'] = $numbersDetected . "_ (" . $result['matFnd'] . ")";
                    }
                    break;
                }
            default:
                $result['matFnd'] = $numbersDetected . "_"; // didn't find any match
                $result['favTi'] = $ti;
        }
        // trim off leading zeroes
        if (isset($result['episEd']) && $result['episEd'] != "") {
            if (is_numeric($result['episEd'])) {
                $result['episEd'] += 0;
            } else {
                twxa_debug($result['matFnd'] . ": " . $result['episEd'] . " is not numeric in $ti\n", -1);
            }
        }
        if (isset($result['episSt']) && $result['episSt'] != "") {
            if (is_numeric($result['episSt'])) {
                $result['episSt'] += 0;
            } else {
                twxa_debug($result['matFnd'] . ": " . $result['episSt'] . " is not numeric in $ti\n", -1);
            }
        }
        if (isset($result['seasEd']) && $result['seasEd'] != "") {
            if (is_numeric($result['seasEd'])) {
                $result['seasEd'] += 0;
            } else {
                twxa_debug($result['matFnd'] . ": " . $result['seasEd'] . " is not numeric in $ti\n", -1);
            }
        }
        if (isset($result['seasSt']) && $result['seasSt'] != "") {
            if (is_numeric($result['seasSt'])) {
                $result['seasSt'] += 0;
            } else {
                twxa_debug($result['matFnd'] . ": " . $result['seasSt'] . " is not numeric in $ti\n", -1);
            }
        }
    } else {
        // handle no-numeral episodes
        $result = matchTitle0_($ti, $seps);
    } //END if(isset($matNums[0]))
    if (!isset($result['seasSt'])) {
        $result['seasSt'] = "";
    }
    if (!isset($result['seasEd'])) {
        $result['seasEd'] = "";
    }
    if (!isset($result['episSt'])) {
        $result['episSt'] = "";
    }
    if (!isset($result['episEd'])) {
        $result['episEd'] = "";
    }
    if (!isset($result['medTyp'])) {
        $result['medTyp'] = "";
    }
    if (!isset($result['itemVr'])) {
        $result['itemVr'] = "";
    }
    if (!isset($result['numSeq'])) {
        $result['numSeq'] = "";
    }
    if (!isset($result['favTi'])) {
        $result['favTi'] = "";
    }
    if (!isset($result['matFnd'])) {
        $result['matFnd'] = "";
    }
    return [
        'seasBatStart' => $result['seasSt'], // detected season batch start
        'seasBatEnd' => $result['seasEd'],
        'episBatStart' => $result['episSt'], // detected episode batch start
        'episBatEnd' => $result['episEd'],
        'mediaType' => $result['medTyp'],
        'itemVersion' => $result['itemVr'],
        'numberSequence' => $result['numSeq'],
        'favTitle' => sanitizeTitle($result['favTi']), // favorite title
        'debugMatch' => $result['matFnd']
    ];
}
