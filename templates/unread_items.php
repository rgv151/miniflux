<?php if (empty($items)): ?>
    <p class="alert alert-info"><?= t('Nothing to read') ?></p>
<?php else: ?>

    <div class="page-header">
        <h2><?= t('Unread') ?><span id="page-counter"><?= isset($nb_items) ? $nb_items : '' ?></span></h2>
        <ul>
            <li>
                <a href="?action=unread&amp;order=updated&amp;direction=<?= $direction == 'asc' ? 'desc' : 'asc' ?>"><?= tne('sort by date %s(%s)%s', '<span class="hide-mobile">',$direction == 'desc' ? t('older first') : t('most recent first'), '</span>') ?></a>
            </li>
            <li>
                <a href="?action=mark-all-read"><?= t('mark all as read') ?></a>
            </li>
        </ul>
    </div>

    <section class="items" id="listing">
        <?php foreach ($items as $item): ?>
            <?= \PicoFarad\Template\load('item', array(
                'item' => $item,
                'menu' => $menu,
                'offset' => $offset,
                'hide' => true,
                'display_mode' => $display_mode,
                'favicons' => $favicons,
                'original_marks_read' => $original_marks_read,
            )) ?>
        <?php endforeach ?>

        <div id="bottom-menu">
            <a href="?action=mark-all-read"><?= t('mark all as read') ?></a>
        </div>

        <?= \PicoFarad\Template\load('paging', array('menu' => $menu, 'nb_items' => $nb_items, 'items_per_page' => $items_per_page, 'offset' => $offset, 'order' => $order, 'direction' => $direction)) ?>
    </section>

<?php endif ?>
