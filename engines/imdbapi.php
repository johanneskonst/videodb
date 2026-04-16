<?php

/**
 * IMDB Api Client
 *
 * @package Engines
 * @author Johannes Konst
 * @link https://imdbapi.dev/
 */

define('IMDBAPI_PREFIX', 'imdb:');
define('IMDBAPI_SERVER', 'https://www.imdbapi.dev/');
define('IMDB_URL', 'https://www.imdb.com/');

function imdbapiMeta() {
    return array(
        'name' => 'IMDB Api',
        'stable' => true,
        'config' => array(
            array(
                'opt' => '_replace_imdb', // $config['imdbapi_replace_imdb']
                'name' => 'Replace IMDB engine',
                'values' => array(
                    'no' => 'No',
                    'yes' => 'Yes'
                ),
                'desc' => 'Set to Yes if you want to use the IMDB-Api engine as a drop-in replacement for the IMDB engine.'
            )
        )
    );
}

function imdbapiCleanId($id) {
    if ('imdb:' == substr($id, 0, strlen('imdb:'))) {
        $id = substr($id, strlen('imdb:'));
    }
    if ('tt' == substr($id, 0, 2)) {
        $id = substr($id, 2);
    }
    return $id;
}

function imdbapiPrefixId($id) {
    return 'imdb:' . imdbapiCleanId($id);
}

function imdbapiSearchUrl($title) {
    return 'https://api.imdbapi.dev/search/titles?limit=10&query=' . urlencode($title);
}

function imdbapiContentUrl($id) {
    $id = imdbapiCleanId($id);
    return 'https://api.imdbapi.dev/titles/tt' . $id;
}

function imdbapiCreditsUrl($id) {
    $id = imdbapiCleanId($id);
    return 'https://api.imdbapi.dev/titles/tt' . $id . '/credits?categories=actor&categories=actress&pageSize=50';
}

// apiActor should be 'removed'
function imdbapiActorUrl($name, $id = null) {
    if (empty($id)) $id = urlencode($name); // no support for searching by name
    $id = imdbapiCleanId($id);
    return 'https://api.imdbapi.dev/names/' . $id;
}

function imdbapiSearch($title, $aka=null) {
    global $CLIENTERROR, $cache;
    $data = array(
        'encoding' => 'utf-8',//$resp['encoding'], // force utf8
        'success' => false
    );
    try {
        $url = imdbapiSearchUrl($title);
        $resp = httpClient($url, $cache);
        if (!$resp['success']) $CLIENTERROR .= $resp['error']."\n";
        if (empty($resp['error'])) {
            $resp['data'] = @json_decode($resp['data'], true);
            if (is_array($resp['data']) && isset($resp['data']['titles']) && is_array($resp['data']['titles'])) {
                $data['success'] = true;
                foreach ($resp['data']['titles'] as $result) {
                    $data[] = array(
                        'id' => imdbapiPrefixId($result['id']),
                        'title' => $result['primaryTitle'],
                        'year' => isset($result['startYear']) ? $result['startYear'] : null,
                        // extra!
                        'type' => $result['type'],
                        'originalTitle' => $result['originalTitle'],
                        'image' => isset($result['primaryImage']) ? $result['primaryImage']['url'] : null,
                        'rating' => isset($result['rating']) ? $result['rating']['aggregateRating'] : null,
                    );
                }
            }
        }
    } catch (Exception $e) {
        $data['error'] = $e->getNessage();
    }
    return $data;
}

function imdbapiData($id) {
    global $CLIENTERROR, $cache;
    $data = array(
        'encoding' => 'utf-8',//$resp['encoding'], // force utf8
        'success' => false
    );
    try {
        $url = imdbapiContentUrl($id);
        $resp = httpClient($url, $cache);
        if (!$resp['success']) $CLIENTERROR .= $resp['error']."\n";
        if (empty($resp['error'])) {
            $resp['data'] = @json_decode($resp['data'], true);
            if (is_array($resp['data']) && isset($resp['data']['id']) && isset($resp['data']['type'])) {
                $data['success'] = true;
                $data['istv'] = ($resp['data']['type'] == 'tvSeries' || $resp['data']['type'] == 'tvMiniSeries' || $resp['data']['type'] == 'tvEpisode') ? 1 : 0; // not reachable from search
                $data['type'] = $resp['data']['type']; // extra
                // keep original behaviour (for now)
                list($t, $s) = explode(' - ', $resp['data']['primaryTitle'], 2);
                if ($s == false) list($t, $s) = explode(': ', $resp['data']['primaryTitle'], 2);
                $data['title'] = $t;
                $data['subtitle'] = $s;
                $data['year'] = isset($resp['data']['startYear']) ? $resp['data']['startYear'] : null;
                $data['endYear'] = isset($resp['data']['endYear']) ? $resp['data']['endYear'] : null; // extra
                $data['primaryTitle'] = $resp['data']['primaryTitle']; // extra
                $data['origtitle'] = isset($resp['data']['originalTitle']) ? $resp['data']['originalTitle'] : null;
                $data['coverurl'] = isset($resp['data']['primaryImage']) ? $resp['data']['primaryImage']['url'] : null;
                $data['mpaa'] = null;
                $data['runtime'] = isset($resp['data']['runtimeSeconds']) ? round($resp['data']['runtimeSeconds'] / 60) : null;
                $data['rating'] = isset($resp['data']['rating']) ? $resp['data']['rating']['aggregateRating'] : null;
                $data['country'] = null;
                if (isset($resp['data']['originCountries']) && is_array($resp['data']['originCountries'])) {
                    $tmp = array();
                    foreach ($resp['data']['originCountries'] as $tmp_i) $tmp[] = $tmp_i['name'];
                    $data['country'] = trim(implode(', ', $tmp));
                }
                $data['language'] = null;
                if (isset($resp['data']['spokenLanguages']) && is_array($resp['data']['spokenLanguages'])) {
                    $tmp = array();
                    foreach ($resp['data']['spokenLanguages'] as $tmp_i) $tmp[] = $tmp_i['name'];
                    $data['language'] = trim(implode(', ', $tmp));
                }
                $data['genres'] = isset($resp['data']['genres']) && is_array($resp['data']['genres']) ? $resp['data']['genres'] : array();
                $data['plot'] = isset($resp['data']['plot']) ? $resp['data']['plot'] : null;
                $data['cast'] = null;
                $data['director'] = null;
                if (isset($resp['data']['directors']) && is_array($resp['data']['directors'])) {
                    $data['director'] = new imdbapiPersonCollection();
                    foreach ($resp['data']['directors'] as $director) {
                        $data['director']->addPerson(new imdbapiPerson($director['id'], $director['displayName'], isset($director['primaryImage']) ? $director['primaryImage']['url'] : null));
                    }
                }
                $data['writer'] = null;
                if (isset($resp['data']['writers']) && is_array($resp['data']['writers'])) {
                    $data['writer'] = new imdbapiPersonCollection();
                    foreach ($resp['data']['writers'] as $writer) {
                        $data['writer']->addPerson(new imdbapiPerson($writer['id'], $writer['displayName'], isset($writer['primaryImage']) ? $writer['primaryImage']['url'] : null));
                    }
                }
                $data['cast'] = null;
                $url_c = imdbapiCreditsUrl($id);
                $resp_c = httpClient($url_c, $cache);
                if (empty($resp_c['error'])) {
                    $tmp = json_decode($resp_c['data'], true);
                    if (isset($tmp['credits']) && is_array($tmp['credits'])) {
                        $cr = $tmp['credits'];
                        $tc = (isset($tmp['totalCount']) ? $tmp['totalCount'] : count($cr));
                        $data['cast'] = new imdbapiPersonCollection();
                        $data['cast']->separator = "\n";
                        foreach ($cr as $c) {
                            $data['cast']->addPerson(new imdbapiPerson(
                                $c['name']['id'],
                                $c['name']['displayName'],
                                isset($c['name']['primaryImage']) ? $c['name']['primaryImage']['url'] : null,
                                implode(', ', $c['characters'])
                            ));
                        }
                    }
                }
            }
            $data['_body'] = $resp['data'];
            $data['no_error'] = json_last_error_msg();
        }
    } catch (Exception $e) {
        $data['error'] = $e->getMessage();
    }
    return $data;
}

function imdbapiActor($name, $actorid = null) {
    global $cache;
    if (empty($actorid)) return;
    $url = imdbapiActorUrl($name, $actorid);
    $data = array(
        array(),
        'encoding' => 'utf-8',//$resp['encoding'], // force utf8
        'success' => false
    );
    try {
        $resp = httpClient($url, $cache);
        if (empty($resp['error'])) {
            $tmp = @json_decode($resp['data'], true);
            if (is_array($tmp)) {
                // Actor-URL: /name/nm0123456
                // Thumbnail: https://...
                $data[0][] = '/name/' . $tmp['id'] . '/';
                $data[0][] = isset($tmp['primaryImage']) ? $tmp['primaryImage']['url'] : null;
                $data['id'] = $tmp['id'];
                $data['name'] = $tmp['displayName'];
                $data['imgurl'] = isset($tmp['primaryImage']) ? $tmp['primaryImage']['url'] : null;
                $data['altnames'] = isset($tmp['alternativeNames']) && count($tmp['alternativeNames']) ? $tmp['alternativeNames'] : array();
                $data['bio'] = isset($tmp['biography']) ? $tmp['biography'] : null;
                $data['height'] = isset($tmp['heightCm']) ? $tmp['heightCm'] : null;
                $data['birthday'] = isset($tmp['birthDate']) ? sprintf('%04d-%02d-%02d', $tmp['birthDate']['year'], $tmp['birthDate']['month'], $tmp['birthDate']['day']) : null;
                $data['born_at'] = isset($tmp['birthLocation']) ? $tmp['birthLocation'] : null;
                $data['success'] = true;
            }
        }
    } catch (Exception $e) {
        $data['error'] = $e->getMessage();
    }
    return $data;
}

class imdbapiPerson {
    function __construct ($id, $name, $image, $character = null) {
        $this->id = $id;
        $this->name = $name;
        $this->image = $image;
        $this->character = $character;
    }
    function __toString() {
        if (!is_null($this->character)) {
            return $this->name . '::' . $this->character . '::' . imdbapiPrefixId($this->id);
        }
        return $this->name;
    }
}

class imdbapiPersonCollection {
    var $separator = ', ';

    function __construct () {
        $this->persons = array();
    }
    function addPerson ($person) {
        $this->persons[] = $person;
    }
    function __toString() {
        $list = array();
        foreach ($this->persons as $person) {
            $list[] = (string) $person;
        }
        return implode($this->separator, $list);
    }
}
