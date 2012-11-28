<?php
class CollectionTreePlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
        'install',
        'uninstall',
        'upgrade',
        'config_form',
        'config',
        'after_save_collection',
        'after_delete_collection',
        'collection_browse_sql',
        'admin_collections_form',
        'admin_collections_show',
        'public_collections_show',
    );

    protected $_filters = array(
        'admin_navigation_main',
        'public_navigation_main',
        'collection_select_options',
    );
    
    /**
     * Install the plugin.
     *
     * One collection can have AT MOST ONE parent collection. One collection can
     * have ZERO OR MORE child collections.
     */
    public function hookInstall()
    {
        // collection_id must be unique to satisfy the AT MOST ONE parent
        // collection constraint.
        $sql  = "
        CREATE TABLE IF NOT EXISTS `{$this->_db->CollectionTree}` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `parent_collection_id` int(10) unsigned NOT NULL,
          `collection_id` int(10) unsigned NOT NULL,
          `name` text COLLATE utf8_unicode_ci,
          PRIMARY KEY (`id`),
          UNIQUE KEY `collection_id` (`collection_id`)
        ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
        $this->_db->query($sql);
        
        set_option('collection_tree_alpha_order', '0');
    }
    
    /**
     * Uninstall the plugin.
     */
    public function hookUninstall()
    {
        $sql = "DROP TABLE IF EXISTS {$this->_db->CollectionTree}";
        $this->_db->query($sql);
        
        delete_option('collection_tree_alpha_order');
    }
    
    /**
     * Upgrade from earlier versions.
     */
    public function hookUpgrade($args)
    {
        // Prior to Omeka 2.0, collection names were stored in the collections 
        // table; now they are stored as Dublin Core Title.
        if (version_compare($args['old_version'], '2.0', '<')) {
            
            // Change the storage engine to InnoDB.
            $sql = "ALTER TABLE {$this->_db->CollectionTree} ENGINE = INNODB";
            $this->_db->query($sql);
            
            // Add the name column to the collection_trees table.
            $sql = "ALTER TABLE {$this->_db->CollectionTree} ADD `name` TEXT NULL";
            $this->_db->query($sql);
            
            // Assign names to their corresponding collection_tree rows.
            $collectionTable = $this->_db->getTable('Collection');
            $collectionTrees = $this->_db->getTable('CollectionTree')->findAll();
            foreach ($collectionTrees as $collectionTree) {
                $collection = $collectionTable->find($collectionTree['collection_id']);
                $collectionTree->name = metadata($collection, array('Dublin Core', 'Title'));
                $collectionTree->save();
            }
        }
    }
    
    /**
     * Display the config form.
     */
    public function hookConfigForm()
    {
?>
<div class="field">
    <div id="collection_tree_alpha_order_label" class="two columns alpha">
        <label for="collection_tree_alpha_order">Order alphabetically?</label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation">Order the collection tree alphabetically? This does 
        not affect the order of the collections browse page.</p>
        <?php echo get_view()->formCheckbox('collection_tree_alpha_order', null, 
        array('checked' => (bool) get_option('collection_tree_alpha_order'))); ?>
    </div>
</div>
<?php
    }
    
    /**
     * Handle the config form.
     */
    public function hookConfig()
    {
        set_option('collection_tree_alpha_order', $_POST['collection_tree_alpha_order']);
    }
    
    /**
     * Save the parent/child relationship.
     */
    public function hookAfterSaveCollection($args)
    {
        $collectionTree = $this->_db->getTable('CollectionTree')->findByCollectionId($args['record']->id);
        
        // Only save the relationship during a form submission.
        if (!isset($args['post'])) {
            if ($collectionTree) {
                // Set the collection name to the tree.
                $collectionTree->name = metadata($args['record'], array('Dublin Core', 'Title'));
                $collectionTree->save();
            }
            return;
        }
        
        // Insert/update the parent/child relationship.
        if ($args['post']['collection_tree_parent_collection_id']) {
            
            // If the collection is not already a child collection, create it.
            if (!$collectionTree) {
                $collectionTree = new CollectionTree;
                $collectionTree->collection_id = $args['record']->id;
            }
            $collectionTree->parent_collection_id = $args['post']['collection_tree_parent_collection_id'];
            $collectionTree->name = metadata($args['record'], array('Dublin Core', 'Title'));
            $collectionTree->save();
            
        // Delete the parent/child relationship if no parent collection is
        // specified.
        } else {
            if ($collectionTree) {
                $collectionTree->delete();
            }
        }
    }
    
    /**
     * Handle collection deletions.
     *
     * Deleting a collection runs the risk of orphaning a child branch. To
     * prevent this, move child collections to the root level. It is the
     * responsibility of the administrator to reassign the child branches to the
     * appropriate parent collection.
     */
    public function hookAfterDeleteCollection($args)
    {
        // Delete the relationship with the parent collection.
        $collectionTree = $this->_db->getTable('CollectionTree')->findByCollectionId($args['record']->id);
        if ($collectionTree) {
            $collectionTree->delete();
        }
        
        // Move child collections to root level by deleting their relationships.
        $collectionTrees = $this->_db->getTable('CollectionTree')->findByParentCollectionId($args['record']->id);
        foreach ($collectionTrees as  $collectionTree) {
            $collectionTree->delete();
        }
    }

    /**
     * Omit all child collections from the collection browse.
     */
    public function hookCollectionBrowseSql($args)
    {
        if (!is_admin_theme()) {
            $sql = "
            c.id NOT IN (
                SELECT nc.collection_id
                FROM {$this->_db->CollectionTree} nc
            )";
            $args['select']->where($sql);
        }
    }
    
    /**
     * Display the parent collection form.
     */
    public function hookAdminCollectionsForm($args)
    {
        $assignableCollections = $this->_db->getTable('CollectionTree')
            ->fetchAssignableParentCollections($args['collection']->id);
        $collectionTable = $this->_db->getTable('Collection');
        $options = array(0 => 'No parent collection');
        foreach ($assignableCollections as $assignableCollection) {
            if ($assignableCollection['name']) {
                $options[$assignableCollection['id']] = $assignableCollection['name'];
            } else {
                $collection = $collectionTable->find($assignableCollection['id']);
                $options[$assignableCollection['id']] = metadata($collection, array('Dublin Core', 'Title'));
            }
        }
        $collectionTree = $this->_db->getTable('CollectionTree')->findByCollectionId($args['collection']->id);
        if ($collectionTree) {
            $parentCollectionId = $collectionTree->parent_collection_id;
        } else {
            $parentCollectionId = null;
        }
?>
<section class="seven columns alpha">
    <h2>Parent Collection</h2>
    <div class="field">
        <div id="collection_tree_parent_collection_id_label" class="two columns alpha">
            <label for="collection_tree_parent_collection_id">Select a Parent Collection</label>
        </div>
        <div class="inputs five columns omega">
            <?php echo get_view()->formSelect('collection_tree_parent_collection_id',
                $parentCollectionId, null, $options); ?>
        </div>
    </div>
</section>
<?php
    }
    
    /**
     * Display the collection's parent collection and child collections.
     */
    public function hookAdminCollectionsShow($args)
    {
        $this->_appendToCollectionsShow($args['collection']);
    }
    
    /**
     * Display the collection's parent collection and child collections.
     */
    public function hookPublicCollectionsShow()
    {
        $this->_appendToCollectionsShow(get_current_record('collection'));
    }
    
    protected function _appendToCollectionsShow($collection)
    {
        $collectionTree = $this->_db->getTable('CollectionTree')->getCollectionTree($collection->id);
?>
<h2>Collection Tree</h2>
<?php echo self::getCollectionTreeList($collectionTree); ?>
<?php
    }
    
    /**
     * Add the collection tree page to the admin navigation.
     */
    public function filterAdminNavigationMain($nav)
    {
        $nav[] = array('label' => 'Collection Tree', 'uri' => url('collection-tree'));
        return $nav;
    }
    
    /**
     * Add the collection tree page to the public navigation.
     */
    public function filterPublicNavigationMain($nav)
    {
        $nav['Collection Tree'] = uri('collection-tree');
        return $nav;
    }
    
    /**
     * Return collection dropdown menu options as a hierarchical tree.
     */
    public function filterCollectionSelectOptions($options)
    {
        return $this->_db->getTable('CollectionTree')->findPairsForSelectForm();
    }
    
    /**
     * Build a nested HTML unordered list of the full collection tree, starting
     * at root collections.
     *
     * @param bool $linkToCollectionShow
     * @return string|null
     */
    public static function getFullCollectionTreeList($linkToCollectionShow = true)
    {
        $rootCollections = get_db()->getTable('CollectionTree')->getRootCollections();
        
        // Return NULL if there are no root collections.
        if (!$rootCollections) {
            return null;
        }
        
        $html = '<ul>';
        foreach ($rootCollections as $rootCollection) {
            $html .= '<li>';
            if ($linkToCollectionShow) {
                $html .= self::linkToCollectionShow($rootCollection['id']);
            } else {
                if ($rootCollection['name']) {
                    $html .= $rootCollection['name'];
                } else {
                    $collectionObj = get_db()->getTable('Collection')->find($rootCollection['id']);
                    $html .=  metadata($collectionObj, array('Dublin Core', 'Title'));
                }
            }
            $collectionTree = get_db()->getTable('CollectionTree')->getDescendantTree($rootCollection['id']);
            $html .= self::getCollectionTreeList($collectionTree, $linkToCollectionShow);
            $html .= '</li>';
        }
        $html .= '</ul>';
        
        return $html;
    }

    /**
     * Recursively build a nested HTML unordered list from the provided
     * collection tree.
     *
     * @see CollectionTreeTable::getCollectionTree()
     * @see CollectionTreeTable::getAncestorTree()
     * @see CollectionTreeTable::getDescendantTree()
     * @param array $collectionTree
     * @param bool $linkToCollectionShow
     * @return string
     */
    public static function getCollectionTreeList($collectionTree, $linkToCollectionShow = true) {
        if (!$collectionTree) {
            return;
        }
        $html = '<ul>';
        foreach ($collectionTree as $collection) {
            $html .= '<li>';
            if ($linkToCollectionShow && !isset($collection['current'])) {
                $html .= self::linkToCollectionShow($collection['id']);
            } else {
                if ($collection['name']) {
                    $html .= $collection['name'];
                } else {
                    $collectionObj = get_db()->getTable('Collection')->find($collection['id']);
                    $html .=  metadata($collectionObj, array('Dublin Core', 'Title'));
                }
            }
            $html .= self::getCollectionTreeList($collection['children'], $linkToCollectionShow);
            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /**
     * Get the HTML link to the specified collection show page.
     *
     * @see link_to_collection()
     * @param int $collectionId
     * @return string
     */
    public static function linkToCollectionShow($collectionId)
    {
        return link_to_collection(null, array(), 'show',
                                  get_db()->getTable('Collection')->find($collectionId));
    }
}
