<?php

/**
 * Software and Role license type.
 */
class CommerceLicenseSoftwareRole extends CommerceLicenseBase  {

  /**
   * Implements CommerceLicenseInterface::isConfigurable().
   */
  public function isConfigurable() {
    return FALSE;
  }

  /**
   * Implements EntityBundlePluginProvideFieldsInterface::fields().
   */
  static function fields() {
    $fields = parent::fields();

    // This field stores the serial_id returned by the software license module.
    // Such a field shouldn't be editable by the customer, of course.
    // Since this license type is not configurable, it's not a problem because
    // there's no form. However, if the license type was configurable,
    // this field instance would need to use the field_extrawidgets_hidden
    // widget provided by the field_extrawidgets module, or set
    // $form['clsr_api_key']['#access'] = FALSE in form().
    $fields['clsr_serial_id']['field'] = array(
      'type' => 'text',
      'cardinality' => 1,
    );
    $fields['clsr_serial_id']['instance'] = array(
      'label' => t('Software License Serial Number'),
      'required' => 1,
      'settings' => array(
        'text_processing' => '0',
      ),
    );

    return $fields;
  }

  /**
   * Implements CommerceLicenseInterface::accessDetails().
   */
  public function accessDetails() {
    $output = field_view_field('commerce_license', $this, 'clsr_serial_id');
    return drupal_render($output);
  }

  /**
   * Overrides Entity::save().
   *
   * Maintains the role, adding or removing it from the owner when necessary.
   */
  public function save() {
		watchdog('commerce_license_software_role', '<pre>In save with: ' . print_r($this->wrapper->clsr_serial_id->value(), TRUE) . '</pre>');
    if ($this->uid && $this->product_id) {
      $role = $this->wrapper->product->commerce_license_software_role->value();
      $owner = $this->wrapper->owner->value();
      // Size (# domains, or # of VMs)
      $product_size = $this->wrapper->product->clsr_size->value();
      // Name is vm or cm for Virtualmin or Cloudmin
      $product_name = $this->wrapper->product->clsr_name->value();
      // Type is "real" or not
      $product_type = $this->wrapper->product->clsr_type->value();
      // can_serials if user can issue new licenses
      $can_serials = $this->wrapper->product->clsr_can_serials->value();
      $save_owner = FALSE; // Save if role gets added/updated.
      $save_license = FALSE; // Save if license gets added/updated.
      if (!empty($this->license_id)) {
        $this->original = entity_load_unchanged('commerce_license', $this->license_id);
        // A plan change occurred. Remove the previous role and change size.
        if ($this->original->product_id && $this->product_id != $this->original->product_id) {
          $previous_role = $this->original->wrapper->product->commerce_license_software_role->value();
          $previous_size = $this->original->wrapper->product->commerce_license_software_role_size->value();
          if (isset($owner->roles[$previous_role])) {
            unset($owner->roles[$previous_role]);
            $save_owner = TRUE;
          }
          if ($this->wrapper->clsr_serial_id->value()) {
            // Load the original software license entity
            $this->software_license_original = entity_load_unchanged('software_license', $this->wrapper->clsr_serial_id->value());
            $software_license = entity_metadata_wrapper('software_license', $this->wrapper->clsr_serial_id->value());
            if ($this->software_license_original->size->value() != $previous_size) {
              $software_license->size->set($product_size);
              $save_license = TRUE;
            }
          }
        }
      }

      // The owner of an active license must have the role. Happens on creation
      // or update.
      if ($this->status == COMMERCE_LICENSE_ACTIVE) {
        if (!isset($owner->roles[$role])) {
          $owner->roles[$role] = $role;
          $save_owner = TRUE;
        }
			// If no serial number associated, create one.
	    if (!($this->wrapper->clsr_serial_id->value()) && $this->expires > 0) {
          watchdog('commerce_license_software_role', '<pre>In software_license creation with commerce_license data: ' . print_r($this, TRUE) . '</pre>');
          // XXX This is fucking ridiculous.
          $results = (new EntityFieldQuery())->entityCondition('entity_type', 'commerce_line_item')->fieldCondition('commerce_license', 'target_id', $this->license_id)->execute();
          // XXX intermediate variables because of strict warning about refs.
          $line_items = array_keys($results['commerce_line_item']);
          $line_item_id = array_shift($line_items);
          $order_query = (new EntityFieldQuery())->entityCondition('entity_type', 'commerce_order')->fieldCondition('commerce_line_items', 'line_item_id', $line_item_id)->execute();
          $orders = array_keys($order_query['commerce_order']);
          $order_id =  array_shift($orders);
          dpm($order_id);
          // Doesn't exist, create it from scratch
          $new_sl_data = array(
            'uid' => $owner->uid,
            'reseller_id' => '1',
            'start_date' => trim(date("Y-m-d H:i:s")),
            'end_date' => trim(date("Y-m-d H:i:s", $this->expires)),
            'product_size' => $product_size,
            'server_max' => '1',
            'type' => $product_type,
            'can_serials' => $can_serials,
            'product_name' => $product_name,
            'order_id' => $order_id,
            'order_product_id' => $this->product_id,
            'trial' => '0',
          );
          $software_license = entity_create('software_license', $new_sl_data);
          $software_license_entity = entity_metadata_wrapper('software_license', $software_license);
          $save_license = TRUE;
        }
        else {
          // Load existing.
					watchdog('commerce_license_software_role', 'Trying to load software_license entity with serial_id: ' . print_r($this->wrapper->clsr_serial_id->value(), TRUE));
          $software_license = entity_load_single('software_license', $this->wrapper->clsr_serial_id->value());
					watchdog('commerce_license_software_role', '$software_license contains: <pre>' . print_r($software_license, TRUE) . '</pre>');
          $software_license_entity = entity_metadata_wrapper('software_license', $software_license);
          // Update the license expiration
          $software_license->end_date = trim(date("Y-m-d H:i:s", $this->expires));
          $save_license = TRUE;
        }
      }
      elseif ($this->status > COMMERCE_LICENSE_ACTIVE) {
        watchdog('comerce_license_software_role', '<pre>In inactive path with commerce_license data: ' . print_r($this, TRUE) . '</pre>');
        // Check for other active software_role licenses, so we don't expire if
        // user owns multiple products.
      	if (empty(commerce_license_software_role_exists($owner->uid))) {
          if (isset($owner->roles[$role])) {
            unset($owner->roles[$role]);
            $save_owner = TRUE;
          }
        }
      }
      if ($this->status == COMMERCE_LICENSE_EXPIRED || $this->status == COMMERCE_LICENSE_REVOKED) {
        // Expire the license right now
        // Load existing.
        watchdog('commerce_license_software_role', 'Trying to load software_license entity with serial_idi for expiration update: ' . print_r($this->wrapper->clsr_serial_id->value(), TRUE));
        $software_license = entity_load_single('software_license', $this->wrapper->clsr_serial_id->value());
        watchdog('commerce_license_software_role', '$software_license contains: <pre>' . print_r($software_license, TRUE) . '</pre>');
        $software_licensed_entity = entity_metadata_wrapper('software_license', $software_license);
        $software_license->end_date = date("Y-m-d H:i:s", commerce_license_get_time());
        $save_license = TRUE;
      }
      // If a role was added or removed, save the owner.
      if ($save_owner) {
        user_save($owner);
      }
      if ($save_license) {
        $software_license_entity->save();
        // Save the serial_id into the clsr_serial_id field, for later
				$this->wrapper->clsr_serial_id->set($software_license_entity->serial_id->value());
      }
    }
    parent::save();
  }
}

