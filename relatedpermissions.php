<?php

require_once 'relatedpermissions.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function relatedpermissions_civicrm_config(&$config) {
  _relatedpermissions_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function relatedpermissions_civicrm_xmlMenu(&$files) {
  _relatedpermissions_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function relatedpermissions_civicrm_install() {
  return _relatedpermissions_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function relatedpermissions_civicrm_uninstall() {
  return _relatedpermissions_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function relatedpermissions_civicrm_enable() {
  return _relatedpermissions_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function relatedpermissions_civicrm_disable() {
  return _relatedpermissions_civix_civicrm_disable();
}

function relatedpermissions_civicrm_alterEntitySettingsFolders(&$folders) {
  static $configured = FALSE;
  if ($configured) return;
  $configured = TRUE;

  $extRoot = dirname( __FILE__ ) . DIRECTORY_SEPARATOR;
  $extDir = $extRoot . 'settings';
  if(!in_array($extDir, $folders)){
    $folders[] = $extDir;
  }
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function relatedpermissions_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _relatedpermissions_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function relatedpermissions_civicrm_managed(&$entities) {
  return _relatedpermissions_civix_civicrm_managed($entities);
}
/*
 * Implement WHERE Clause - we find the contacts for whom this contact has permission and
 * specifically give permission to them
 */
function relatedpermissions_civicrm_aclWhereClause($type, &$tables, &$whereTables, &$contactID, &$where) {
  if (!$contactID) {
    return;
  }
  $tmpTableName = _relatedpermissions_get_permissionedtable($contactID);

  $tables ['$tmpTableName'] = $whereTables ['$tmpTableName'] =
    " LEFT JOIN $tmpTableName permrelationships
     ON (contact_a.id = permrelationships.contact_id)";
  if(empty($where)){
    $where = " permrelationships.contact_id IS NOT NULL ";
  }
  else{
    $where = '(' . $where . " OR permrelationships.contact_id IS NOT NULL " . ')';
  }
}
/*
 * Create temporary table of all permissioned contacts.
 * If the contacts are organisations then we want all contacts they have permission
 * over. Note that in order to avoid ORs & unindexed fields in the ON clause we use several queries
 */
function _relatedpermissions_get_permissionedtable($contactID) {
  $tmpTableName = 'myrelationships' . rand(10000, 100000);
  $datekey=date('dhis');
  $tmpTableSecondaryContacts = 'mysecondaryrelationships' . $datekey. rand(10000, 100000);
  $now = date('Y-m-d');
  $sql = "CREATE TEMPORARY TABLE $tmpTableName
  (
   `contact_id` INT(10) NULL DEFAULT NULL,
   PRIMARY KEY (`contact_id`)
  )";
  CRM_Core_DAO::executeQuery($sql);
  $sql = "CREATE TEMPORARY TABLE $tmpTableSecondaryContacts
  (
   `contact_id` INT(10) NULL DEFAULT NULL,
   PRIMARY KEY (`contact_id`)
  )";
  CRM_Core_DAO::executeQuery($sql);
  $sql = "INSERT INTO $tmpTableName
    SELECT DISTINCT contact_id_a FROM civicrm_relationship
    WHERE contact_id_b = $contactID
    AND is_active = 1
    AND (start_date IS NULL OR start_date <= '{$now}' )
    AND (end_date IS NULL OR end_date >= '{$now}')
    AND is_permission_b_a = 1
  ";
  CRM_Core_DAO::executeQuery($sql);

  $sql = "REPLACE INTO $tmpTableName
    SELECT contact_id_b FROM civicrm_relationship
    WHERE contact_id_a = $contactID
    AND is_active = 1
    AND (start_date IS NULL OR start_date <= '{$now}' )
    AND (end_date IS NULL OR end_date >= '{$now}')
    AND is_permission_a_b = 1
  ";
    CRM_Core_DAO::executeQuery($sql);
  /*
  * Next we generate a table of the permissioned contacts permissioned contacts for Orgs & Households
  */

  $sql = "INSERT INTO $tmpTableSecondaryContacts
    SELECT DISTINCT contact_id_b
    FROM $tmpTableName tmp
    LEFT JOIN civicrm_relationship r  ON tmp.contact_id = r.contact_id_a
    INNER JOIN civicrm_contact c ON c.id = r.contact_id_a AND c.contact_type IN ('Household', 'Organization')
    WHERE
    r.is_active = 1
    AND (start_date IS NULL OR start_date <= '{$now}' )
    AND (end_date IS NULL OR end_date >= '{$now}')
    AND is_permission_a_b = 1
  ";
  CRM_Core_DAO::executeQuery($sql);

  $sql = "REPLACE INTO $tmpTableSecondaryContacts
    SELECT contact_id_a
    FROM $tmpTableName tmp
    LEFT JOIN civicrm_relationship r ON tmp.contact_id = r.contact_id_b
    INNER JOIN civicrm_contact c ON c.id = r.contact_id_b AND c.contact_type IN ('Household', 'Organization')
    WHERE
    r.is_active = 1
    AND (start_date IS NULL OR start_date <= '{$now}' )
    AND (end_date IS NULL OR end_date >= '{$now}')
    AND is_permission_b_a = 1
  ";
  CRM_Core_DAO::executeQuery($sql);

  $sql = "REPLACE INTO $tmpTableName
    SELECT * FROM $tmpTableSecondaryContacts";
  CRM_Core_DAO::executeQuery($sql);

  return $tmpTableName;
}

/**
 * Set permissions if required
 * @param unknown $a
 * @param unknown $b
 */
function relatedpermissions_civicrm_pre($op, $entity, $objectID, &$entityArray) {
  if($entity != 'Relationship' || $op == 'delete') {
    return;
  }
  $relationshipType = explode('_', $entityArray['relationship_type_id']);

  if(_relatedpermissions_is_permission($relationshipType[0], 'a_b')) {
    $entityArray['is_permission_a_b'] = TRUE;
  }
  if(_relatedpermissions_is_permission($relationshipType[0], 'b_a')) {
    $entityArray['is_permission_b_a'] = TRUE;
  }
}

/**
 * Get permission for a given entity id in a given direction
 * @param integer $entity_id
 * @param string $direction
 * @return Ambigous <null, array>
 */
function _relatedpermissions_is_permission($entity_id, $direction) {
  static $settings = array();
  if(!isset($settings[$entity_id])) {
    $entity_settings = civicrm_api3('entity_setting', 'get', array(
      'key' => 'nz.co.fuzion.relatedpermissions',
      'entity_id' => $entity_id,
      'entity_type' => 'relationship_type')
    );
    $settings = $entity_settings['values'][$entity_id];
  }
  return CRM_Utils_Array::value('always_permission_' . $direction, $settings);
}
