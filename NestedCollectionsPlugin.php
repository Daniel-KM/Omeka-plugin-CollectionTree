<?php
require_once 'Omeka/Plugin/Abstract.php';
class NestedCollectionsPlugin extends Omeka_Plugin_Abstract
{
    protected $_hooks = array(
        'install', 
        'uninstall', 
        'after_save_form_record', 
        'collection_browse_sql', 
        'admin_append_to_collections_form', 
        'admin_append_to_collections_show_primary', 
        'public_append_to_collections_show',
    );
    
    /**
     * Install the plugin.
     * 
     * A limited release version (v0.1, named "Nested") of this plugin may still 
     * be used by a handful of early adopters. Because of the plugin name 
     * change, they must delete the Nested plugin directory without uninstalling 
     * the plugin, save this plugin in the plugins directory, and install this 
     * plugin as normal.
     */
    public function install()
    {
        $sql  = "
        CREATE TABLE IF NOT EXISTS {$this->_db->NestedCollection} (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            parent_collection_id int(10) unsigned NOT NULL,
            child_collection_id int(10) unsigned NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY child_collection_id (child_collection_id)
        ) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
        $this->_db->exec($sql);
        
        
        // Determine if the old Nested plugin is installed.
        $nested = $this->_db->getTable('Plugin')->findByDirectoryName('Nested');
        if ($nested && $nested->version == '0.1') {
            
            // Delete Nested from the plugins table.
            $nested->delete();
            
            // Populate the new table.
            $sql = "
            INSERT INTO {$db->NestedCollection} (
                parent_collection_id
                child_collection_id, 
            ) 
            SELECT parent, child
            FROM {$db->prefix}nests";
            $this->_db->exec($sql);
            
            // Delete the old table.
            $sql = "DROP TABLE {$db->prefix}nests";
            $this->_db->query($sql);
        }
    }
    
    /**
     * Uninstall the plugin.
     */
    public function uninstall()
    {
        $sql = "DROP TABLE IF EXISTS {$this->_db->NestedCollection}";
        $this->_db->query($sql);
    }
    
    /**
     * Save the parent/child relationship.
     */
    public function afterSaveFormRecord($record, $post)
    {
        // Only process collection forms.
        if (!($record instanceof Collection)) {
            return;
        }
        
        $nestedCollection = $this->_db->getTable('NestedCollection')
                                      ->findByChildCollectionId($record->id);
        
        // Insert/update the parent/child relationship.
        if ($post['nested_collections_parent_collection_id']) {
            
            // If the collection is not already a child collection, create it.
            if (!$nestedCollection) {
                $nestedCollection = new NestedCollection;
                $nestedCollection->child_collection_id = $record->id;
            }
            $nestedCollection->parent_collection_id = $post['nested_collections_parent_collection_id'];
            $nestedCollection->save();
        
        // Delete the parent/child relationship if no parent collection is 
        // specified.
        } else {
            if ($nestedCollection) {
                $nestedCollection->delete();
            }
        }
    }
    
    /**
     * Omit all child collections from the collection browse.
     */
    public function collectionBrowseSql($select, $params)
    {
        if (!is_admin_theme()) {
            $sql = "
            c.id NOT IN (
                SELECT nc.child_collection_id 
                FROM {$this->_db->NestedCollection} nc
            )";
            $select->where($sql);
        }
    }
    
    /**
     * Display the parent collection form.
     */
    public function adminAppendToCollectionsForm($collection)
    {
        $assignableCollections =$this->_db->getTable('NestedCollection')
                                          ->fetchAssignableParentCollections($collection->id);
        $options = array(0 => 'No parent collection');
        foreach ($assignableCollections as $assignableCollection) {
            $options[$assignableCollection['id']] = $assignableCollection['name'];
        }
        $nestedCollection = $this->_db->getTable('NestedCollection')
                                      ->findByChildCollectionId($collection->id);
?>
<h2>Parent Collection</h2>
<div class="field">
    <?php echo __v()->formLabel('nested_collections_parent_collection_id','Select a Parent Collection'); ?>
    <div class="inputs">
        <?php echo __v()->formSelect('nested_collections_parent_collection_id', 
                                     $nestedCollection->parent_collection_id, 
                                     null, 
                                     $options); ?>
    </div>
</div>
<?php
    }
    
    /**
     * Display the collection's parent collection and child collections.
     */
    public function adminAppendToCollectionsShowPrimary($collection)
    {
        // Show this collection's parent collection.
        $parent = $this->_db->getTable('NestedCollection')
                            ->fetchParent($collection->id);
        
        // Show this collection's child collections.
        $children = $this->_db->getTable('NestedCollection')
                              ->fetchChildren($collection->id);
    }
    
    /**
     * Display the collection's parent collection and child collections.
     */
    public function publicAppendToCollectionsShow($collection)
    {
        // Show this collection's parent collection.
        $parent = $this->_db->getTable('NestedCollection')
                            ->fetchParent($collection->id);
        
        // Show this collection's child collections.
        $children = $this->_db->getTable('NestedCollection')
                              ->fetchChildren($collection->id);
    }
}
