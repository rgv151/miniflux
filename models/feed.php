<?php

namespace Model\Feed;

use UnexpectedValueException;
use Model\Config;
use Model\Item;
use SimpleValidator\Validator;
use SimpleValidator\Validators;
use PicoDb\Database;
use PicoFeed\Serialization\Export;
use PicoFeed\Serialization\Import;
use PicoFeed\Reader\Reader;
use PicoFeed\Reader\Favicon;
use PicoFeed\PicoFeedException;
use PicoFeed\Client\InvalidUrlException;

const LIMIT_ALL = -1;

// Store the favicon
function store_favicon($feed_id, $link, $icon)
{
    return Database::get('db')
            ->table('favicons')
            ->save(array(
                'feed_id' => $feed_id,
                'link' => $link,
                'icon' => $icon,
            ));
}

// Download favicon
function fetch_favicon($feed_id, $site_url, $icon_link)
{
    if (Config\get('favicons') == 1 && ! has_favicon($feed_id)) {
        $favicon = new Favicon;

        $link = $favicon->find($site_url, $icon_link);
        $icon = $favicon->getDataUri();

        if ($icon !== '') {
            store_favicon($feed_id, $link, $icon);
        }
    }
}

// Return true if the feed have a favicon
function has_favicon($feed_id)
{
    return Database::get('db')->table('favicons')->eq('feed_id', $feed_id)->count() === 1;
}

// Get favicons for those feeds
function get_favicons(array $feed_ids)
{
    if (Config\get('favicons') == 0) {
        return array();
    }

    $db = Database::get('db')
            ->hashtable('favicons')
            ->columnKey('feed_id')
            ->columnValue('icon');

    // pass $feeds_ids as argument list to hashtable::get(), use ... operator with php 5.6+
    return call_user_func_array(array($db, 'get'), $feed_ids);
}

// Get all favicons for a list of items
function get_item_favicons(array $items)
{
    $feed_ids = array();

    foreach ($items as $item) {
        $feed_ids[$item['feed_id']] = $item['feed_id'];
    }

    return get_favicons($feed_ids);
}

// Get all favicons
function get_all_favicons()
{
    if (Config\get('favicons') == 0) {
        return array();
    }

    return Database::get('db')
            ->hashtable('favicons')
            ->getAll('feed_id', 'icon');
}

// Update feed information
function update(array $values)
{
    return Database::get('db')
            ->table('feeds')
            ->eq('id', $values['id'])
            ->save(array(
                'title' => $values['title'],
                'site_url' => $values['site_url'],
                'feed_url' => $values['feed_url'],
                'enabled' => $values['enabled'],
                'rtl' => $values['rtl'],
                'download_content' => $values['download_content'],
                'cloak_referrer' => $values['cloak_referrer'],
            ));
}

// Export all feeds
function export_opml()
{
    $opml = new Export(get_all());
    return $opml->execute();
}

// Import OPML file
function import_opml($content)
{
    $import = new Import($content);
    $feeds = $import->execute();

    if ($feeds) {

        $db = Database::get('db');
        $db->startTransaction();

        foreach ($feeds as $feed) {

            if (! $db->table('feeds')->eq('feed_url', $feed->feed_url)->count()) {

                $db->table('feeds')->save(array(
                    'title' => $feed->title,
                    'site_url' => $feed->site_url,
                    'feed_url' => $feed->feed_url
                ));
            }
        }

        $db->closeTransaction();

        Config\write_debug();

        return true;
    }

    Config\write_debug();

    return false;
}

// Add a new feed from an URL
function create($url, $enable_grabber = false, $force_rtl = false, $cloak_referrer = false)
{
    $feed_id = false;

    $db = Database::get('db');

    // Discover the feed
    $reader = new Reader(Config\get_reader_config());
    $resource = $reader->discover($url);

    // Feed already there
    if ($db->table('feeds')->eq('feed_url', $resource->getUrl())->count()) {
        throw new UnexpectedValueException;
    }

    // Parse the feed
    $parser = $reader->getParser(
        $resource->getUrl(),
        $resource->getContent(),
        $resource->getEncoding()
    );

    if ($enable_grabber) {
        $parser->enableContentGrabber();
    }

    $feed = $parser->execute();

    // Save the feed
    $result = $db->table('feeds')->save(array(
        'title' => $feed->getTitle(),
        'site_url' => $feed->getSiteUrl(),
        'feed_url' => $feed->getFeedUrl(),
        'download_content' => $enable_grabber ? 1 : 0,
        'rtl' => $force_rtl ? 1 : 0,
        'last_modified' => $resource->getLastModified(),
        'last_checked' => time(),
        'etag' => $resource->getEtag(),
        'cloak_referrer' => $cloak_referrer ? 1 : 0,
    ));

    if ($result) {
        $feed_id = $db->getConnection()->getLastId();

        Item\update_all($feed_id, $feed->getItems());
        fetch_favicon($feed_id, $feed->getSiteUrl(), $feed->getIcon());
    }

    return $feed_id;
}

// Refresh all feeds
function refresh_all($limit = LIMIT_ALL)
{
    foreach (@get_ids($limit) as $feed_id) {
        refresh($feed_id);
    }

    // Auto-vacuum for people using the cronjob
    Database::get('db')->getConnection()->exec('VACUUM');

    return true;
}

// Refresh one feed
function refresh($feed_id)
{
    try {

        $feed = get($feed_id);

        if (empty($feed)) {
            return false;
        }

        $reader = new Reader(Config\get_reader_config());

        $resource = $reader->download(
            $feed['feed_url'],
            $feed['last_modified'],
            $feed['etag']
        );

        // Update the `last_checked` column each time, HTTP cache or not
        update_last_checked($feed_id);

        // Feed modified
        if ($resource->isModified()) {

            $parser = $reader->getParser(
                $resource->getUrl(),
                $resource->getContent(),
                $resource->getEncoding()
            );

            if ($feed['download_content']) {

                $parser->enableContentGrabber();

                // Don't fetch previous items, only new one
                $parser->setGrabberIgnoreUrls(
                    Database::get('db')->table('items')->eq('feed_id', $feed_id)->findAllByColumn('url')
                );
            }

            $feed = $parser->execute();

            update_cache($feed_id, $resource->getLastModified(), $resource->getEtag());

            Item\update_all($feed_id, $feed->getItems());
            fetch_favicon($feed_id, $feed->getSiteUrl(), $feed->getIcon());
        }

        update_parsing_error($feed_id, 0);
        Config\write_debug();

        return true;
    }
    catch (InvalidUrlException $e) {
        // disable($feed_id);
    }
    catch (PicoFeedException $e) {
    }

    update_parsing_error($feed_id, 1);
    Config\write_debug();

    return false;
}

// Get the list of feeds ID to refresh
function get_ids($limit = LIMIT_ALL)
{
    $query = Database::get('db')->table('feeds')->eq('enabled', 1)->asc('last_checked');

    if ($limit !== LIMIT_ALL) {
        $query->limit((int) $limit);
    }

    return $query->findAllByColumn('id');
}

// get number of feeds with errors
function count_failed_feeds()
{
    return Database::get('db')
        ->table('feeds')
        ->eq('parsing_error', '1')
        ->count();
}

// Get all feeds
function get_all()
{
    return Database::get('db')
        ->table('feeds')
        ->asc('title')
        ->findAll();
}

// Get all feeds with the number unread/total items in the order failed, working, disabled
function get_all_item_counts()
{
    return Database::get('db')
        ->table('feeds')
        ->columns(
            'feeds.*',
            'SUM(CASE WHEN items.status IN ("unread") THEN 1 ELSE 0 END) as "items_unread"',
            'SUM(CASE WHEN items.status IN ("read", "unread") THEN 1 ELSE 0 END) as "items_total"'
          )
        ->join('items', 'feed_id', 'id')
        ->groupBy('feeds.id')
        ->desc('feeds.parsing_error')
        ->desc('feeds.enabled')
        ->asc('feeds.title')
        ->findAll();
}

// Get unread/total count for one feed
function count_items($feed_id)
{
    $counts = Database::get('db')
        ->table('items')
        ->columns('status', 'count(*) as item_count')
        ->in('status', array('read', 'unread'))
        ->eq('feed_id', $feed_id)
        ->groupBy('status')
        ->findAll();

    $result = array(
        'items_unread' => 0,
        'items_total' => 0,
    );

    foreach ($counts as &$count) {

        if ($count['status'] === 'unread') {
            $result['items_unread'] = (int) $count['item_count'];
        }

        $result['items_total'] += $count['item_count'];
    }

    return $result;
}

// Get one feed
function get($feed_id)
{
    return Database::get('db')
        ->table('feeds')
        ->eq('id', $feed_id)
        ->findOne();
}

// Update parsing error column
function update_parsing_error($feed_id, $value)
{
    Database::get('db')->table('feeds')->eq('id', $feed_id)->save(array('parsing_error' => $value));
}

// Update last check date
function update_last_checked($feed_id)
{
    Database::get('db')
        ->table('feeds')
        ->eq('id', $feed_id)
        ->save(array(
            'last_checked' => time()
        ));
}

// Update Etag and last Modified columns
function update_cache($feed_id, $last_modified, $etag)
{
    Database::get('db')
        ->table('feeds')
        ->eq('id', $feed_id)
        ->save(array(
            'last_modified' => $last_modified,
            'etag'          => $etag
        ));
}

// Remove one feed
function remove($feed_id)
{
    // Items are removed by a sql constraint
    return Database::get('db')->table('feeds')->eq('id', $feed_id)->remove();
}

// Remove all feeds
function remove_all()
{
    return Database::get('db')->table('feeds')->remove();
}

// Enable a feed (activate refresh)
function enable($feed_id)
{
    return Database::get('db')->table('feeds')->eq('id', $feed_id)->save((array('enabled' => 1)));
}

// Disable feed
function disable($feed_id)
{
    return Database::get('db')->table('feeds')->eq('id', $feed_id)->save((array('enabled' => 0)));
}

// Validation for edit
function validate_modification(array $values)
{
    $v = new Validator($values, array(
        new Validators\Required('id', t('The feed id is required')),
        new Validators\Required('title', t('The title is required')),
        new Validators\Required('site_url', t('The site url is required')),
        new Validators\Required('feed_url', t('The feed url is required')),
    ));

    $result = $v->execute();
    $errors = $v->getErrors();

    return array(
        $result,
        $errors
    );
}
