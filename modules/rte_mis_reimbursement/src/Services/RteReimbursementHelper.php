<?php

namespace Drupal\rte_mis_reimbursement\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\eck\EckEntityInterface;
use Drupal\workflow\Entity\WorkflowTransitionInterface;

/**
 * Class RteReimbursementHelper.
 *
 * Provides helper functions for rte mis allocation module.
 */
class RteReimbursementHelper {

  use StringTranslationTrait;

  /**
   * Array of possible states transitions for single level approval.
   *
   * @var array
   */
  const POSSIBLE_TRANSITIONS = [
    // From submitted state.
    'reimbursement_claim_workflow_submitted' => [
      'reimbursement_claim_workflow_approved_by_beo',
      'reimbursement_claim_workflow_rejected',
      'reimbursement_claim_workflow_reset',
    ],
    // From BEO approved state.
    'reimbursement_claim_workflow_approved_by_beo' => [
      'reimbursement_claim_workflow_approved_by_deo',
      'reimbursement_claim_workflow_rejected',
      'reimbursement_claim_workflow_submitted',
    ],
  ];

  /**
   * Array of states transitions associated with payment approver.
   *
   * @var array
   */
  const PAYMENT_APPROVAL_TRANSITIONS = [
    'reimbursement_claim_workflow_approved_by_deo_payment_completed',
    'reimbursement_claim_workflow_approved_by_deo_payment_pending',
    'reimbursement_claim_workflow_payment_pending_payment_completed',
  ];

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  public $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The user account service.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new RteReimbursementHelper object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, AccountInterface $account, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $account;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Determines whether single level approval is enabled or not.
   *
   * @return bool
   *   TRUE if single level approval is enabled, FALSE otherwise.
   */
  public function isSingleLevelApprovalEnabled(): bool {
    // Get approval level from reimbursement config if not set
    // we consider it as 'dual' level.
    $approval_level = $this->configFactory->get('rte_mis_reimbursement.settings')->get('approval_level') ?? '';

    return $approval_level == 'single';
  }

  /**
   * Act on workflow `reimbursement_claim_workflow`.
   *
   * This function makes transition from different states when single level
   * approval is enabled for reimbursement.
   *
   * @param \Drupal\workflow\Entity\WorkflowTransitionInterface $transition
   *   The transition object.
   */
  public function processSingleLevelApproval(WorkflowTransitionInterface $transition): void {
    // This array contains keys as from states and values is possible states
    // transition.
    $possible_transition = self::POSSIBLE_TRANSITIONS;
    // Get the from sid.
    $from_sid = $transition->getFromSid();
    // Get the to sid.
    $to_sid = $transition->getToSid();
    $to_sids = $possible_transition[$from_sid] ?? [];
    if (in_array($to_sid, $to_sids)) {
      // Execute the transition, mark this as force as we are overriding
      // workflow.
      $transition->execute(TRUE);
    }
  }

  /**
   * Disables the states transitions associated with payment approver.
   *
   * This function unsets available transitions from 'approved by deo' state.
   *
   * @param array $transitions
   *   Array of transitions.
   */
  public function disablePaymentApprovalTransitions(array &$transitions): void {
    // This array contains values of transitions to disable.
    $disabled_transitions = self::PAYMENT_APPROVAL_TRANSITIONS;
    // Unset the transitions.
    foreach ($disabled_transitions as $transition) {
      unset($transitions[$transition]);
    }
  }

  /**
   * Checks if user should be allowed to update school claim mini node or not.
   *
   * @param \Drupal\eck\EckEntityInterface $entity
   *   School claim mini node.
   *
   * @return bool
   *   TRUE if user can update school claim mini node, FALSE otherwise.
   */
  public function canUpdateReimbursementClaim(EckEntityInterface $entity): bool {
    $access = FALSE;
    $reimbursement_status = $entity->get('field_reimbursement_claim_status')->getString();
    $roles = $this->currentUser->getRoles();
    // Get payment approver from reimbursement configurations.
    $payment_approver = $this->configFactory->get('rte_mis_reimbursement.settings')->get('payment_approver') ?? 'state';
    // Dyanmically creating role as per the configured payment approver
    // so that we can match approver with the user roles.
    $approver_role = "{$payment_approver}_admin";
    // Get approval level.
    $is_single_level_approval = $this->isSingleLevelApprovalEnabled();
    // For district admin.
    if (in_array('district_admin', $roles)) {
      // For single approval, district can update the status
      // if current status is either submitted or approved by beo.
      if ($is_single_level_approval) {
        if (in_array($reimbursement_status, [
          'reimbursement_claim_workflow_submitted',
          'reimbursement_claim_workflow_approved_by_beo',
        ])) {
          return TRUE;
        }
      }
      // For dual approval, district can update the status
      // if current status is approved by beo.
      else {
        if ($reimbursement_status == 'reimbursement_claim_workflow_approved_by_beo') {
          return TRUE;
        }
      }
    }

    // For block admin.
    if (in_array('block_admin', $roles)) {
      // Block can only update the status if dual level approval is
      // configured and current status is submitted.
      if (!$is_single_level_approval && $reimbursement_status == 'reimbursement_claim_workflow_submitted') {
        return TRUE;
      }
    }

    // If approver role matches the current user role than we check
    // that the admin can update the reimbursement status if current status
    // is either 'approved by deo' or 'paymennt pending'.
    if (in_array($approver_role, $roles) && in_array($reimbursement_status, [
      'reimbursement_claim_workflow_approved_by_deo',
      'reimbursement_claim_workflow_payment_pending',
    ])) {
      return TRUE;
    }

    // In all other cases the update access should be denied.
    return $access;
  }

  /**
   * Function to return the table both heading and rows.
   *
   * @param string $school_id
   *   School Id.
   * @param string $academic_year
   *   Academic Year.
   * @param string $approval_authority
   *   Approval Authority.
   * @param array $additional_fees
   *   Additional Fees Information.
   *
   * @return array
   *   Returns the data for rows.
   */
  public function loadStudentData(?string $school_id = NULL, ?string $academic_year = NULL, ?string $approval_authority = NULL, array $additional_fees = []): array {
    $data = [];
    // Get the list of all the classes from config.
    $school_config = $this->configFactory->get('rte_mis_school.settings');
    $config_class_list = $school_config->get('field_default_options.class_level');
    $class_list = array_keys($config_class_list);
    $class_list_selected = $this->getClassList($approval_authority);
    $class_list = array_intersect($class_list, !empty($class_list_selected) ? $class_list_selected : $class_list);
    $current_user_entity = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    $user_linked_school = $school_id;
    $current_user_roles = $this->currentUser->getRoles(TRUE);
    if ($school_id == NULL && in_array('school_admin', $current_user_roles)) {
      $udise_code = $current_user_entity->getDisplayName() ?? NULL;
      // Check for the details of school in the requested academic year.
      $user_linked_school = $this->getSchoolDetails($udise_code, $academic_year);
      if (!$user_linked_school) {
        $this->loggerFactory->get('rte_mis_reimbursement')
          ->notice('There is no school found for the school with UDISE code: @udise and academic year: @academic_year', [
            '@udise' => $udise_code,
            '@academic_year' => str_replace('_', '-', $academic_year),
          ]);
        return [];
      }
    }
    $node_ids = $this->getStudentList($academic_year, $class_list, $user_linked_school);
    // Process nodes in chunks, for large data set.
    $node_chunks = array_chunk($node_ids, 100);

    // Current user fee details.
    $current_user_fee_details = $this->schoolFeeDetails($user_linked_school);
    // Academic Year and Approval Authority is set,
    // then calculate the government fee.
    if (isset($academic_year) && isset($approval_authority)) {
      // Government fees will be calculated by form_state authority,
      // current academic year.
      $government_fee = $this->stateDefinedFees($academic_year, $approval_authority, $user_linked_school);
    }
    $slno = 1;
    foreach ($node_chunks as $chunk) {
      $rows = [];
      $rows['slno'] = $slno;
      $student_performance_entities = $this->entityTypeManager->getStorage('mini_node')->loadMultiple($chunk);
      foreach ($student_performance_entities as $student_performance_entity) {
        $rows = [];
        $row_keys = [
          'field_student_name', 'field_parent_name', 'field_current_class', 'field_medium',
          'field_gender', 'field_entry_class_for_allocation',
        ];
        $row_values = [];
        foreach ($row_keys as $value) {
          $row_values[$value] = $student_performance_entity->get($value)->getString();
        }
        $class_value = $row_values['field_current_class'];
        $student_medium = $row_values['field_medium'];
        $student_gender = $row_values['field_gender'];
        $rows['slno'] = $slno;
        // Student Name.
        $rows['student_name'] = $row_values['field_student_name'];
        // Parent Name.
        $rows['parent_name'] = $row_values['field_parent_name'];
        // Class.
        $rows['current_class'] = ucwords($config_class_list[$class_value]);
        $rows['type'] = $this->t('New');
        if ($row_values['field_entry_class_for_allocation'] != $row_values['field_current_class']) {
          $rows['type'] = $this->t('Old');
        }
        // Medium.
        $rows['field_medium'] = $student_medium;
        // School Tution Fee.
        $rows['school_tution_fee'] = $this->schoolTutionDetails($current_user_fee_details, $student_gender, $student_medium, $class_value) ?? 0;
        // Set default value.
        $government_tution_fees = 0;
        $education_level = NULL;

        if (isset($current_user_fee_details)) {
          // Get the genders from the config.
          $genders = array_keys($school_config->get('field_default_options.field_education_type'));

          // 'boy' => ['boys', 'co-ed'].
          // 'girl' => ['girls', 'co-ed'].
          // 'transgender' => ['co-ed', 'boys', 'girls'].
          $gender_priorities = [
            'boy' => array_diff($genders, ['girls']),
            'girl' => array_diff($genders, ['boys']),
            'transgender' => array_reverse($genders),
          ];
          // Get the gender categories to check for the given gender.
          $genders_to_check = $gender_priorities[$student_gender] ?? [];
          $found = FALSE;
          // Iterate over the possible gender keys.
          foreach ($genders_to_check as $gender_key) {
            // Loop through the array keys of $current_user_fee_details.
            foreach (array_keys($current_user_fee_details) as $key) {
              // Split the key into its parts education_type,
              // education_level, and medium.
              if (strpos($key, $gender_key) === 0) {
                // Split the key into its parts:
                // education_type, medium, and education_level.
                $parts = explode('_', $key);
                // Get the education level.
                if (count($parts) === 3) {
                  $education_level = $parts[2];
                  $found = TRUE;
                  break;
                }
              }
            }
            // Break the outer loop if a match was found.
            if ($found) {
              break;
            }
          }

        }

        foreach ($government_fee as $value) {
          if ($education_level && $value['education_level'] == $education_level) {
            $government_tution_fees = $value['tution_fee'] ?? 0;
          }
        }

        // Total return 0 if anything goes wrong.
        $total = min($rows['school_tution_fee'], $government_tution_fees) ?? 0;

        // Additional fees processing.
        if ($additional_fees = array_filter($additional_fees)) {
          foreach ($additional_fees as $key => $value) {
            if (is_numeric($key)) {
              if ($government_fee) {
                foreach ($government_fee as $gov_fee) {
                  if ($gov_fee['education_level'] == $education_level) {
                    $rows[$value['value']] = $gov_fee[$value['value']] ?? 0;
                    $total += $rows[$value['value']];
                  }
                }
              }
              else {
                $rows[$value['value']] = 0;
                $total += $rows[$value['value']];
              }
            }
          }
        }
        // State defined tution fee for the matching approval authority.
        $rows['government_fee'] = $government_tution_fees;
        $rows['total'] = number_format($total, 2, '.', '');
        $slno++;
        $data[] = $rows;
      }
    }
    // Return the data array.
    return $data;
  }

  /**
   * Function to count the fee details of a particular school.
   *
   * @param string $school_id
   *   School MiniNode id.
   *
   * @return array
   *   Returns an array of school fees details.
   */
  public function schoolFeeDetails(?string $school_id = NULL): array {
    // Mapped array based on class.
    $school_fees = [];
    if ($school_id) {
      $school_mini_node = $this->entityTypeManager->getStorage('mini_node')->load($school_id);
      if ($school_mini_node instanceof EckEntityInterface) {
        // Get the education details.
        $education_details = $school_mini_node->get('field_education_details') ?? NULL;
        $education_details_entity = $education_details ? $education_details->referencedEntities() : NULL;
        // For each entry of education detail, check
        // And store value in an nested array.
        foreach ($education_details_entity as $value) {
          $education_type = $value->get('field_education_type')->getString() ?? NULL;
          $education_level = $value->get('field_education_level')->getString() ?? NULL;
          $medium = $value->get('field_medium')->getString() ?? NULL;
          // Concatenate and generate a unique key.
          $key = $education_type . '_' . $medium . '_' . $education_level;
          // Fee Details for each education detail.
          $fee_details = $value->get('field_fee_details')->referencedEntities();
          // For each fee detail get class value and fee amount.
          foreach ($fee_details as $fee_paragraph) {
            $school_fees[$key][$fee_paragraph->get('field_class_list')->getString()] =
              $fee_paragraph->get('field_total_fees')->getString() ?? NULL;
          }
        }
      }
    }
    return $school_fees;
  }

  /**
   * Gets the fee based on gender, medium, and class.
   *
   * @param array $school_fees
   *   The school fees array.
   * @param string $gender
   *   The gender of the student (boy, girl, transgender).
   * @param string $medium
   *   The medium of education.
   * @param int $class
   *   The class for which fee is required.
   *
   * @return string|null
   *   Returns the fee if found, or NULL if no matching entry is found.
   */
  public function schoolTutionDetails(array $school_fees, string $gender, string $medium, int $class): string|null {
    // Get the list of all the classes from config.
    $school_config = $this->configFactory->get('rte_mis_school.settings');
    $genders = array_keys($school_config->get('field_default_options.field_education_type'));

    // Define the gender categories to check in order of priority.
    // 'boy' => ['boys', 'co-ed'].
    // 'girl' => ['girls', 'co-ed'].
    // 'transgender' => ['co-ed', 'boys', 'girls'].
    $gender_priorities = [
      'boy' => array_diff($genders, ['girls']),
      'girl' => array_diff($genders, ['boys']),
      'transgender' => array_reverse($genders),
    ];
    // Get the gender categories to check for the given gender.
    $genders_to_check = $gender_priorities[$gender] ?? [];
    // Initialize variables to store the latest fee found.
    $latest_fee = NULL;

    // Iterate over the possible gender keys.
    foreach ($genders_to_check as $gender_key) {
      // Create the key for the current gender and medium combination.
      $combination = $gender_key . '_' . $medium;
      // Iterate over each entry for this gender and medium combination.
      foreach ($school_fees as $key => $entry) {
        if (strpos($key, $combination) === 0) {
          foreach ($school_fees[$key] as $given_class => $fee) {
            // Check if the class matches.
            if ($given_class == $class) {
              $latest_fee = $fee;
            }
          }
        }
      }
    }

    // Return the latest fee found or NULL if no match was found.
    return $latest_fee;
  }

  /**
   * Function to get the fee defined by state/central.
   *
   * @param string $academic_year
   *   Academic Year.
   * @param string $approval_authority
   *   Approval Authority.
   * @param string $school_id
   *   School MiniNode id.
   *
   * @return array
   *   Return State Defined Fees.
   */
  public function stateDefinedFees(?string $academic_year = NULL, ?string $approval_authority = NULL, ?string $school_id = NULL): array {
    $school_total_fee_values = [];
    $school_fee_mininodes = $this->entityTypeManager->getStorage('mini_node')->loadByProperties([
      'type' => 'school_fee_details',
      'field_academic_year' => $academic_year,
      'field_payment_head' => $approval_authority,
    ]);

    $school_fee_mininodes = reset($school_fee_mininodes);
    if ($school_fee_mininodes instanceof EckEntityInterface) {
      $fee_details = $school_fee_mininodes->get('field_state_fees')->referencedEntities() ?? NULL;
      foreach ($fee_details as $value) {
        $board_type = $value->get('field_board_type')->getString() ?? NULL;
        if ($school_id) {
          $user_linked_school = $this->entityTypeManager->getStorage('mini_node')->load($school_id);
          if ($user_linked_school instanceof EckEntityInterface) {
            if ($board_type == $user_linked_school->get('field_board_type')->getString()) {
              $school_fee_values = [];
              $school_fee_values['education_level'] = $value->get('field_education_level')->getString() ?? 0;
              $school_fee_values['tution_fee'] = $value->get('field_fees_amount')->getString() ?? 0;
              $additional_fee = $value->get('field_reimbursement_fees_type')->referencedEntities() ?? NULL;
              foreach ($additional_fee as $value) {
                $school_fee_values[$value->get('field_fees_type')->getString()] = $value->get('field_amount')->getString() ?? 0;
              }
              $school_total_fee_values[] = $school_fee_values;
            }
          }
        }
      }
    }
    return $school_total_fee_values;
  }

  /**
   * This will be an entity query to get the school details.
   *
   * @param string $udise_code
   *   School Udise Code.
   * @param string $academic_year
   *   Academic year.
   *
   * @return string|null
   *   Returns the fee if found, or NULL if school details found.
   */
  public function getSchoolDetails(?string $udise_code = NULL, ?string $academic_year = NULL): ?string {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'school',
      'name' => $udise_code,
    ]);
    $term = reset($term);

    // Entity Query to get the school with matching udise code
    // school name and academic year and return the school entity.
    $query = $this->entityTypeManager->getStorage('mini_node')
      ->getQuery()
      ->condition('type', 'school_details')
      ->accessCheck(FALSE);

    if ($udise_code) {
      $query->condition('field_udise_code', $term->id());
    }
    if ($academic_year) {
      $query->condition('field_academic_year', $academic_year);
    }
    $miniNodes = $query->execute();

    return !empty($miniNodes) ? reset($miniNodes) : NULL;
  }

  /**
   * Callback to get the list of allowed values.
   *
   * @param array $additional_fees
   *   Additional Fees Information.
   *
   * @return array
   *   Return Table Headers.
   */
  public function tableHeading(array $additional_fees = []): array {
    $header = [
      'serial_number' => $this->t('SNO'),
      'student_name' => $this->t('Student Name'),
      'mobile_number' => $this->t('Gaurdian Name'),
      'class' => $this->t('Pre-session Class'),
      'application_type' => $this->t('New/Old'),
      'parent_name' => $this->t('Medium'),
      'school_fees' => $this->t('School Tution Fees (₹)'),
    ];
    // Check if there are additional fees values.
    if (!empty($additional_fees)) {
      // Loop through the additional fees and append to the header dynamically.
      foreach ($additional_fees as $fee) {
        $value = $fee['value'] ?? NULL;
        if ($value) {
          $header[$value] = $this->t('@value Fees (₹)', ['@value' => ucfirst($fee['value'])]);
        }
      }
    }
    $header['goverment_fees'] = $this->t('Govt Fees (₹)');
    $header['Total'] = $this->t('Total (₹)');
    return $header;
  }

  /**
   * Check if a mini node with the same bundle and field values exists.
   *
   * @param string $bundle
   *   The bundle of the mini node to check.
   * @param string $academic_year
   *   Academic year value.
   * @param string $approval_authority
   *   Payment head value.
   * @param string $school_id
   *   Current School Id.
   *
   * @return bool
   *   TRUE if an entry exists, FALSE otherwise.
   */
  public function checkExistingClaimMiniNode($bundle, $academic_year, $approval_authority, $school_id): bool {
    // Perform an entity query to check for existing published mini nodes.
    $query = $this->entityTypeManager->getStorage('mini_node')->getQuery()
      ->condition('type', $bundle)
      ->condition('field_academic_session_claim', $academic_year)
      ->condition('field_payment_head', $approval_authority)
      ->condition('status', TRUE)
      ->accessCheck(FALSE);
    if ($school_id) {
      $query->condition('field_school', $school_id);
    }
    // Execute the query.
    $existing_node_ids = $query->execute();

    // If there are any results, an entry exists.
    return !empty($existing_node_ids);
  }

  /**
   * Returns mini nodes with the same bundle and field values exists.
   *
   * @param string $bundle
   *   The bundle of the mini node to check.
   * @param string $academic_year
   *   Academic year value.
   * @param string $approval_authority
   *   Payment head value.
   * @param string $school_id
   *   Current School Id.
   * @param bool $status
   *   Mini node status.
   *
   * @return array
   *   An array of mini node ids.
   */
  public function getExistingClaimMiniNode($bundle, $academic_year, $approval_authority, $school_id, $status = TRUE): array {
    // Perform an entity query to check for existing published mini nodes.
    $query = $this->entityTypeManager->getStorage('mini_node')->getQuery()
      ->condition('type', $bundle)
      ->condition('field_academic_session_claim', $academic_year)
      ->condition('field_payment_head', $approval_authority)
      ->condition('status', $status)
      ->accessCheck(FALSE);
    if ($school_id) {
      $query->condition('field_school', $school_id);
    }
    // Execute the query.
    $existing_node_ids = $query->execute();

    // Return mini node ids.
    return $existing_node_ids;
  }

  /**
   * Check if there are students based on paramters.
   *
   * @param string $academic_year
   *   Academic Year.
   * @param array $class_list
   *   List of class.
   * @param string $school_id
   *   School Id.
   *
   * @return array
   *   Node ids.
   */
  public function getStudentList(?string $academic_year = NULL, array $class_list = [], ?string $school_id = NULL): array {
    $query = $this->entityTypeManager->getStorage('mini_node')->getQuery()
      ->condition('type', 'student_performance')
      ->accessCheck(FALSE);
    if (isset($academic_year)) {
      $query->condition('field_academic_session_tracking', $academic_year);
    }
    if (!empty($class_list)) {
      $query->condition('field_current_class', $class_list, 'IN');
    }
    if (isset($school_id)) {
      $query->condition('field_school', $school_id);
    }
    $node_ids = $query->execute();
    // Return the list of student node IDs.
    return $node_ids;

  }

  /**
   * Check if there are students based on paramters.
   *
   * @param string $approval_authority
   *   Payment Head.
   *
   * @return array
   *   Class list.
   */
  public function getClassList(?string $approval_authority = NULL): array {
    $class_list_selected = [];
    if ($approval_authority == 'central_head') {
      $school_config = $this->configFactory->get('rte_mis_school.settings');
      // Consider till class 8th.
      $class_levels = $school_config->get('field_default_options.class_level') ?? [];

      foreach ($class_levels as $key => $class_level) {
        // Consider only students from class 1st to 8th for the central.
        if ($key >= 3) {
          $class_list_selected[] = $key;
          // Search the key for the value till class 8th.
          if ($class_level == '8th') {
            break;
          }
        }
      }
    }
    elseif ($approval_authority == 'state_head') {
      // Check in config, if state payment head is allowed.
      $reimbursement_config = $this->configFactory->get('rte_mis_reimbursement.settings');
      $state_fee_status = $reimbursement_config->get('payment_heads.enable_state_head');
      if ($state_fee_status) {
        // Consider till class 8th.
        $class_levels = $reimbursement_config->get('payment_heads.state_class_list') ?? [];
        $class_list_selected = $class_levels;
      }
      else {
        $class_list_selected = [];
      }
    }
    // Return the list of student node IDs.
    return $class_list_selected;
  }

}
