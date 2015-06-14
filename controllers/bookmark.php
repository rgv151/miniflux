<?php

use PicoFarad\Router;
use PicoFarad\Response;
use PicoFarad\Request;
use PicoFarad\Template;
use PicoFeed\Syndication\Atom;

// Ajax call to add or remove a bookmark
Router\post_action('bookmark', function() {

    $id = Request\param('id');
    $value = Request\int_param('value');

    Response\json(array(
        'id' => $id,
        'value' => $value,
        'result' => Model\Item\set_bookmark_value($id, $value),
    ));
});

// Add new bookmark
Router\get_action('bookmark', function() {

    $id = Request\param('id');
    $menu = Request\param('menu', 'unread');
    $source = Request\param('source', 'unread');
    $offset = Request\int_param('offset', 0);
    $feed_id = Request\int_param('feed_id', 0);

    Model\Item\set_bookmark_value($id, Request\int_param('value'));

    if ($source === 'show') {
        Response\Redirect('?action=show&menu='.$menu.'&id='.$id);
    }

    Response\Redirect('?action='.$menu.'&offset='.$offset.'&feed_id='.$feed_id.'#item-'.$id);
});

// Display bookmarks page
Router\get_action('bookmarks', function() {

    $offset = Request\int_param('offset', 0);
    $nb_items = Model\Item\count_bookmarks();
    $items = Model\Item\get_bookmarks($offset, Model\Config\get('items_per_page'));

    Response\html(Template\layout('bookmarks', array(
        'favicons' => Model\Feed\get_item_favicons($items),
        'original_marks_read' => Model\Config\get('original_marks_read'),
        'order' => '',
        'direction' => '',
        'display_mode' => Model\Config\get('items_display_mode'),
        'items' => $items,
        'nb_items' => $nb_items,
        'offset' => $offset,
        'items_per_page' => Model\Config\get('items_per_page'),
        'nothing_to_read' => Request\int_param('nothing_to_read'),
        'nb_unread_items' => Model\Item\count_by_status('unread'),
        'menu' => 'bookmarks',
        'title' => t('Bookmarks').' ('.$nb_items.')'
    )));
});

// Display bookmark feeds
Router\get_action('bookmark-feed', function() {

    // Check token
    $feed_token = Model\Config\get('feed_token');
    $request_token = Request\param('token');

    if ($feed_token !== $request_token) {
        Response\text('Access Forbidden', 403);
    }

    // Build Feed
    $writer = new Atom;
    $writer->title = t('Bookmarks').' - Miniflux';
    $writer->site_url = Helper\get_current_base_url();
    $writer->feed_url = $writer->site_url.'?action=bookmark-feed&token='.urlencode($feed_token);

    $bookmarks = Model\Item\get_bookmarks();

    foreach ($bookmarks as $bookmark) {

        $article = Model\Item\get($bookmark['id']);

        $writer->items[] = array(
            'id' => $article['id'],
            'title' => $article['title'],
            'updated' => $article['updated'],
            'url' => $article['url'],
            'content' => $article['content'],
        );
    }

    Response\xml($writer->execute());
});
