<?php

namespace Drupal\xml_form_builder\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;

use XMLFormRepository;

/**
 * Default controller for the xml_form_builder module.
 */
class DefaultController extends ControllerBase {

  public function xml_form_builder_main() {
    module_load_include('inc', 'xml_form_builder', 'XMLFormRepository');
    $names = XMLFormRepository::GetNames();

    // No forms exist can only create.
    if (count($names) == 0) {
      return '<div>No forms are defined. Please create a new form.</div><br/>';
    }

    $table = [
      '#header' => [
        ['data' => t('Title')],
        ['data' => t('Type')],
        [
          'data' => t('Operations'),
          'colspan' => 6,
        ],
      ],
      '#rows' => [],
    ];

    foreach ($names as $form_info) {
      $name = $form_info['name'];
      if ($form_info['indb']) {
        $type = t('Custom');
        $edit = Link::createFromRoute(
          t('Edit'),
          'xml_form_builder.edit',
          ['form_name' => $name]
        );
        $delete = Link::createFromRoute(
          t('Delete'),
          'xml_form_builder.delete',
          ['form_name' => $name]
        );
      }
      else {
        $type = t('Built-in');
        $edit = '';
        $delete = '';
      }
      $copy = Link::createFromRoute(
        t('Copy'),
        'xml_form_builder.copy',
        ['form_name' => $name]
      );
      $view = Link::createFromRoute(
        t('View'),
        'xml_form_builder.preview',
        ['form_name' => $name]
      );
      $export = Link::createFromRoute(
        t('Export'),
        'xml_form_builder.export',
        ['form_name' => $name]
      );
      $associate = Link::createFromRoute(
        t('Associate'),
        'xml_form_builder.associations_form',
        ['form_name' => $name]
      );

      $table['#rows'][] = [
        $name,
        $type,
        $copy,
        $edit,
        $view,
        $export,
        $delete,
        $associate,
      ];
    }
    $table['#type'] = 'table';
    return $table;
  }

  /**
   * Show the Associations page.
   *
   * Here, the user can view which forms are enabled for each content model.
   *
   * @return array
   *   The table to display.
   */
  public function xml_form_builder_list_associations() {
    module_load_include('inc', 'xml_form_builder', 'includes/associations');

    $associations_list = [
      '#theme' => 'item_list',
      '#items' => [],
    ];

    $associations = xml_form_builder_get_associations([], [], [], TRUE);
    $map = [];

    foreach ($associations as $association) {
      $cmodel = $association['content_model'];
      $form = $association['form_name'];
      if (!isset($map[$cmodel])) {
        $map[$cmodel] = [];
      }
      $map[$cmodel][] = $form;
    }
    ksort($map);

    // Returns a link to the edit associations form for form $form_name.
    $create_form_association_link = function ($form_name) {
      return [Link::createFromRoute($form_name, 'xml_form_builder.associations_form', ['form_name' => $form_name])];
    };

    foreach ($map as $cmodel => $forms) {
      $form_table = [
        '#type' => 'table',
        '#rows' => array_map($create_form_association_link, $forms),
      ];
      $object = islandora_object_load($cmodel);
      if ($object) {
        $label = $object->label . " ($cmodel)";
      }
      else {
        $label = $cmodel;
      }
      $associations_list['#items'][] = ['#markup' => $label . \Drupal::service("renderer")->render($form_table)];
    }

    return [$associations_list];
  }

  public function xml_form_builder_export($form_name) {
    module_load_include('inc', 'xml_form_builder', 'XMLFormRepository');
    header('Content-Type: text/xml', TRUE);
    header('Content-Disposition: attachment; filename="' . $form_name . '.xml"');
    $definition = XMLFormRepository::Get($form_name);
    $definition->formatOutput = TRUE;
    echo $definition->saveXML();
    exit();
  }

  public function xml_form_builder_edit($form_name) {
    module_load_include('inc', 'xml_form_builder', 'XMLFormDatabase');

    if (!XMLFormDatabase::Exists($form_name)) {
      drupal_set_message(t('Form "%name" does not exist.', [
        '%name' => $form_name
        ]), 'error');
      drupal_not_found();
      exit();
    }

    xml_form_builder_edit_include_css();
    xml_form_builder_edit_include_js();
    xml_form_builder_create_element_type_store();
    xml_form_builder_create_element_store($form_name);
    xml_form_builder_create_properties_store($form_name);
    return '<div id="xml-form-builder-editor"></div>';
  }

  public function xml_form_builder_edit_save($form_name) {
    module_load_include('inc', 'xml_form_builder', 'JSONFormDefinition');
    module_load_include('inc', 'xml_form_builder', 'XMLFormDatabase');
    module_load_include('inc', 'xml_form_api', 'XMLFormDefinition');
    try {
      // @TODO: this data needs to be sanitized. Can we get this data through the
    // form API?
      $definition = new JSONFormDefinition($_POST['data']);
      list($properties, $form) = $definition->getPropertiesAndForm();
      $definition = XMLFormDefinitionGenerator::Create($properties, $form);
      XMLFormDatabase::Update($form_name, $definition);
    }

      catch (Exception $e) {
      $msg = "File: {$e->getFile()}<br/>Line: {$e->getLine()}<br/>Error: {$e->getMessage()}";
      drupal_set_message(\Drupal\Component\Utility\Xss::filter($msg), 'error');
    }
  }

  public function xml_form_builder_disable_association($form_name, $id) {
    module_load_include('inc', 'xml_form_builder', 'includes/associations');
    $association = xml_form_builder_get_association($id);
    if (!isset($association)) {
      drupal_set_message(t('Specified association does not exist.'), 'error');
      drupal_goto(xml_form_builder_get_associate_form_path($form_name));
      return;
    }
    // Database defined association.
    if ($association['in_db']) {
      db_delete('xml_form_builder_form_associations')
        ->condition('id', intval($id))
        ->execute();
      drupal_set_message(t('Deleted the association ID:%id from %form_name.', [
        '%id' => $id,
        '%form_name' => $form_name,
      ]));
    }
    else {
      // Hook defined association.
      $num_results = db_select('xml_form_builder_association_hooks', 'fa')
        ->fields('fa')
        ->condition('id', $id)
        ->countQuery()
        ->execute()
        ->fetchField();
      if ($num_results == 1) {
        db_update('xml_form_builder_association_hooks')
          ->fields(['enabled' => (int) FALSE])
          ->condition('id', $id)
          ->execute();
      }
      else {
        db_insert('xml_form_builder_association_hooks')
          ->fields([
          'id' => $id,
          'enabled' => (int) FALSE,
        ])
          ->execute();
      }
      drupal_set_message(t('Successfully disabled association.'));
    }
    drupal_goto(xml_form_builder_get_associate_form_path($form_name));
  }

  public function xml_form_builder_enable_association($form_name, $id) {
    module_load_include('inc', 'xml_form_builder', 'includes/associations');
    $association = xml_form_builder_get_association($id);
    if (!isset($association)) {
      drupal_set_message(t('Specified association does not exist.'), 'error');
      drupal_goto(xml_form_builder_get_associate_form_path($form_name));
      return;
    }
    // Hook defined association, can't enable non hook associations.
    if (!$association['in_db']) {
      $num_results = db_select('xml_form_builder_association_hooks', 'fa')
        ->fields('fa')
        ->condition('id', $id)
        ->countQuery()
        ->execute()
        ->fetchField();
      if ($num_results == 1) {
        db_update('xml_form_builder_association_hooks')
          ->fields(['enabled' => (int) TRUE])
          ->condition('id', $id)
          ->execute();
      }
      else {
        db_insert('xml_form_builder_association_hooks')
          ->fields([
          'id' => $id,
          'enabled' => (int) TRUE,
        ])
          ->execute();
      }
    }
    drupal_set_message(t('Successfully enabled association.'));
    drupal_goto(xml_form_builder_get_associate_form_path($form_name));
  }


  public function XmlFormBuilderDatastreamForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state, AbstractObject $object = NULL, $dsid = NULL) {
    $form_state->loadInclude('xml_form_builder', 'inc', 'includes/datastream.form');
    // Leave this here for legacy reasons.
    $form_state->set(['datastream'], isset($object[$dsid]) ? $object[$dsid] : FALSE);
    $associations = xml_form_builder_datastream_form_get_associations($form_state, $object->models, $dsid);
    $association = xml_form_builder_datastream_form_get_association($form_state, $associations);
    return isset($association) ?
      xml_form_builder_datastream_form_metadata_form($form, $form_state, $object, $association) :
      xml_form_builder_datastream_form_select_association_form($form, $form_state, $associations);
  }
}
