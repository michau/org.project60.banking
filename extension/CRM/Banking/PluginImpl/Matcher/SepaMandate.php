<?php
/*-------------------------------------------------------+
| Project 60 - CiviBanking                               |
| Copyright (C) 2013-2014 SYSTOPIA                       |
| Author: B. Endres (endres -at- systopia.de)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL v3 license. You can redistribute it and/or  |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/


require_once 'CRM/Banking/Helpers/OptionValue.php';
require_once 'packages/eval-math/evalmath.class.php';

/**
 * This matcher tries to reconcile the payments with existing contributions. 
 * There are two modes:
 *   default      - matches e.g. to pending contributions and changes the status to completed
 *   cancellation - matches negative amounts to completed contributions and changes the status to cancelled
 */
class CRM_Banking_PluginImpl_Matcher_SepaMandate extends CRM_Banking_PluginModel_Matcher {

  /**
   * class constructor
   */ 
  function __construct($config_name) {
    parent::__construct($config_name);

    // read config, set defaults
    $config = $this->_plugin_config;
    if (!isset($config->threshold)) $config->threshold = 0.5;
    if (!isset($config->received_date_minimum)) $config->received_date_minimum = "-10 days";
    if (!isset($config->received_date_maximum)) $config->received_date_maximum = "+10 days";
    if (!isset($config->deviation_penalty)) $config->deviation_penalty = 0.1;
    if (!isset($config->value_propagation)) $config->value_propagation = array();

    if (!isset($config->cancellation_enabled)) $config->cancellation_enabled = FALSE;
    if (!isset($config->cancellation_general_penalty)) $config->cancellation_general_penalty = 0.0;
    if (!isset($config->cancellation_default_reason)) $config->cancellation_default_reason = ts("Unspecified SEPA cancellation");
    if (!isset($config->cancellation_date_minimum)) $config->cancellation_date_minimum = "-10 days";
    if (!isset($config->cancellation_date_maximum)) $config->cancellation_date_maximum = "+30 days";
    if (!isset($config->cancellation_amount_relative_minimum)) $config->cancellation_amount_relative_minimum = 1.0;
    if (!isset($config->cancellation_amount_relative_maximum)) $config->cancellation_amount_relative_maximum = 1.0;
    if (!isset($config->cancellation_amount_absolute_minimum)) $config->cancellation_amount_absolute_minimum = 1.0;
    if (!isset($config->cancellation_amount_absolute_maximum)) $config->cancellation_amount_absolute_maximum = 1.0;
    if (!isset($config->cancellation_amount_penalty)) $config->cancellation_amount_penalty = $config->deviation_penalty;
    if (!isset($config->cancellation_penalty_threshold)) $config->cancellation_penalty_threshold = $config->deviation_penalty;
    if (!isset($config->cancellation_value_propagation)) $config->cancellation_value_propagation = $config->value_propagation;

    // extended cancellation features: enter cancel_reason
    if (!isset($config->cancellation_cancel_reason))         $config->cancellation_cancel_reason         = 0; // set to 1 to enable
    if (!isset($config->cancellation_cancel_reason_edit))    $config->cancellation_cancel_reason_edit    = 1; // set to 0 to disable user input
    if (!isset($config->cancellation_cancel_reason_source))  $config->cancellation_cancel_reason_source  = 'cancel_reason';
    if (!isset($config->cancellation_cancel_reason_default)) $config->cancellation_cancel_reason_default = ts('Unknown');

    // extended cancellation features: fee
    if (!isset($config->cancellation_cancel_fee))            $config->cancellation_cancel_fee            = 0; // set to 1 to enable
    if (!isset($config->cancellation_cancel_fee_edit))       $config->cancellation_cancel_fee_edit       = 1; // set to 0 to disable user input
    if (!isset($config->cancellation_cancel_fee_source))     $config->cancellation_cancel_fee_source     = 'cancellation_fee'; // external source field in btx->data_parsed
    if (!isset($config->cancellation_cancel_fee_store))      $config->cancellation_cancel_fee_store      = 'match.cancel_fee'; // where to store the calculated fee, for syntax see value_propagation
    if (!isset($config->cancellation_cancel_fee_default))    $config->cancellation_cancel_fee_default    = 'difference';  // evaluated term, valid variables: 'difference'- (btx->amount + contribution->total_amount), 'source'- content of btx->data_parsed[$config->cancellation_cancel_fee_source]
    // add to value_propagation
    if ($config->cancellation_cancel_fee && !empty($config->cancellation_cancel_fee_store)) {
      // add entry to value propagation
      if (!isset($config->cancellation_value_propagation)) $config->cancellation_value_propagation = array();
      $config->cancellation_value_propagation->{'match.cancel_fee'} = $config->cancellation_cancel_fee_store;
    }

    // create activity
    if (!isset($config->cancellation_create_activity))             $config->cancellation_create_activity             = false;
    if (!isset($config->cancellation_create_activity_type_id))     $config->cancellation_create_activity_type_id     = 37;
    if (!isset($config->cancellation_create_activity_subject))     $config->cancellation_create_activity_subject     = ts("Follow-up SEPA Cancellation");
    if (!isset($config->cancellation_create_activity_assignee_id)) $config->cancellation_create_activity_assignee_id = 0; // will be replaced with current user
    if (!isset($config->cancellation_create_activity_text))        $config->cancellation_create_activity_text        = '';
  }

  /** 
   * Generate a set of suggestions for the given bank transaction
   * 
   * @return array(match structures)
   */
  public function match(CRM_Banking_BAO_BankTransaction $btx, CRM_Banking_Matcher_Context $context) {
    $config = $this->_plugin_config;
    $threshold   = $this->getThreshold();
    $data_parsed = $btx->getDataParsed();
    $probability = 1.0 - $this->getPenalty($btx);
    $cancellation_mode = ((bool) $config->cancellation_enabled) && ($btx->amount < 0);

    // look for the 'sepa_mandate' key
    if (empty($data_parsed['sepa_mandate'])) return null;

    // now load the mandate
    $mandate_reference = $data_parsed['sepa_mandate'];
    $mandate = civicrm_api('SepaMandate', 'getsingle', array('version'=>3, 'reference'=>$mandate_reference));
    if (!empty($mandate['is_error'])) {
      CRM_Core_Session::setStatus(sprintf(ts("Couldn't load SEPA mandate for reference %s"), $mandate_reference), ts('Error'), 'error');
      return null;
    }

    // find the contribution
    if ($mandate['type']=='OOFF' && $mandate['entity_table']=='civicrm_contribution') {
      $contribution_id = $mandate['entity_id'];
    } elseif ($mandate['entity_table']=='civicrm_contribution_recur') {
      $contribution_recur_id = $mandate['entity_id'];
      $value_date = strtotime($btx->value_date);
      if ($cancellation_mode) {
        $earliest_date = date('Ymdhis', strtotime($config->cancellation_date_minimum, $value_date));
        $latest_date = date('Ymdhis', strtotime($config->cancellation_date_maximum, $value_date));        
      } else {
        $earliest_date = date('Ymdhis', strtotime($config->received_date_minimum, $value_date));
        $latest_date = date('Ymdhis', strtotime($config->received_date_maximum, $value_date));        
      }

      $contribution_id = 0;
      $find_contribution_query = "
      SELECT  id
      FROM    civicrm_contribution
      WHERE   contribution_recur_id=$contribution_recur_id
      AND     receive_date <= DATE('$latest_date')
      AND     receive_date >= DATE('$earliest_date');";
      $found_contribution = CRM_Core_DAO::executeQuery($find_contribution_query);
      while ($found_contribution->fetch()) {
        if (!$contribution_id) {
          $contribution_id = $found_contribution->id;
        } else {
          // this is the second contribution found!
          CRM_Core_Session::setStatus(ts("There was more than one matching contribution found! Try to configure the plugin with a smaller search time span."), ts('Error'), 'error');
          return null;
        }
      }

      if (!$contribution_id) {
        // no contribution found
        CRM_Core_Session::setStatus(ts("There was no matching contribution! Try to configure the plugin with a larger search time span."), ts('Error'), 'error');
        return null;        
      }

    } else {
      error_log("org.project60.sepa: matcher_sepa: Bad mandate type.");
      return null;
    }

    // now, let's have a look at this contribution and its contact...
    $contribution = civicrm_api('Contribution', 'getsingle', array('id'=>$contribution_id, 'version'=>3));
    if (!empty($contribution['is_error'])) {      
        CRM_Core_Session::setStatus(ts("The contribution connected to this mandate could not be read."), ts('Error'), 'error');
        return null;
    }
    $contact = civicrm_api('Contact', 'getsingle', array('id'=>$contribution['contact_id'], 'version'=>3));
    if (!empty($contact['is_error'])) {      
        CRM_Core_Session::setStatus(ts("The contact connected to this mandate could not be read."), ts('Error'), 'error');
        return null;
    }

    // now: create a suggestion
    $suggestion = new CRM_Banking_Matcher_Suggestion($this, $btx);
    $suggestion->setParameter('contribution_id', $contribution_id);
    $suggestion->setParameter('contact_id', $contribution['contact_id']);
    $suggestion->setParameter('mandate_id', $mandate['id']);
    $suggestion->setParameter('mandate_reference', $mandate_reference);
    
    if (!$cancellation_mode) {
      // STANDARD SUGGESTION:
      $suggestion->setTitle(ts("SEPA SDD Transaction"));

      // add penalties for deviations in amount,status,deleted contact
      if ($btx->amount != $contribution['total_amount']) {
        $suggestion->addEvidence($config->deviation_penalty, ts("The contribution does not feature the expected amount."));
        $probability -= $config->deviation_penalty;
      }
      $status_inprogress = banking_helper_optionvalue_by_groupname_and_name('contribution_status', 'In Progress');
      if ($contribution['contribution_status_id'] != $status_inprogress) {
        $suggestion->addEvidence($config->deviation_penalty, ts("The contribution does not have the expected status 'in Progress'."));
        $probability -= $config->deviation_penalty;
      }
      if (!empty($contact['contact_is_deleted'])) {
        $suggestion->addEvidence($config->deviation_penalty, ts("The contact this mandate belongs to has been deleted."));
        $probability -= $config->deviation_penalty;
      }

    } else {
      // CANCELLATION SUGGESTION:
      $suggestion->setTitle(ts("Cancel SEPA SDD Transaction"));
      $suggestion->setParameter('cancellation_mode', $cancellation_mode);

      // calculate penalties (based on CRM_Banking_PluginImpl_Matcher_ExistingContribution::rateContribution)
      $contribution_amount = $contribution['total_amount'];
      $target_amount = -$context->btx->amount;
      $amount_range_rel = $contribution_amount * ($config->cancellation_amount_relative_maximum - $config->cancellation_amount_relative_minimum);
      $amount_range_abs = $config->cancellation_amount_absolute_maximum - $config->cancellation_amount_absolute_minimum;
      $amount_range = max($amount_range_rel, $amount_range_abs);
      $amount_delta = $contribution_amount - $target_amount;

      // check for amount limits      
      if ($amount_range) {
        $penalty = $config->cancellation_amount_penalty * (abs($amount_delta) / $amount_range);
        if ($penalty > $config->cancellation_penalty_threshold) {
          $suggestion->addEvidence($config->cancellation_amount_penalty, ts("The cancellation fee, i.e. the deviation from the original amount, is not in the specified range."));
          $probability -= $penalty;
        }
      }

      // add general cancellation penalty, if set
      $probability -= (float) $config->cancellation_general_penalty;

      // generate cancellation extra parameters
      if ($config->cancellation_cancel_reason) {
        // determine the cancel reason
        if (empty($data_parsed[$config->cancellation_cancel_reason_source])) {
          $suggestion->setParameter('cancel_reason', $config->cancellation_cancel_reason_default);
        } else {
          $suggestion->setParameter('cancel_reason', $data_parsed[$config->cancellation_cancel_reason_source]);
        }
      }
      if ($config->cancellation_cancel_fee) {
        // calculate / determine the cancellation fee
        try {
          $meval = new EvalMath();
          // first initialise variables 'difference' and 'source'
          $meval->evaluate("difference = -{$btx->amount} - {$contribution_amount}");
          if (empty($config->cancellation_cancel_fee_source) || empty($data_parsed[$config->cancellation_cancel_fee_source])) {
            $meval->evaluate("source = 0.0");
          } else {
            $meval->evaluate("source = {$data_parsed[$config->cancellation_cancel_fee_source]}");
          }
          $suggestion->setParameter('cancel_fee', $meval->evaluate($config->cancellation_cancel_fee_default));
        } catch (Exception $e) {
          error_log("org.project60.banking.matcher.existing: Couldn't calculate cancellation_fee. Error was: $e");
        }
      }
    }

    // store it
    $suggestion->setProbability($probability);
    $btx->addSuggestion($suggestion);

    return $this->_suggestions;
  }

  /**
   * Handle the different actions, should probably be handles at base class level ...
   * 
   * @param type $match
   * @param type $btx
   */
  public function execute($match, $btx) {
    $cancellation_mode = $match->getParameter('cancellation_mode');
    if (!empty($cancellation_mode)) {
      // CANCELLATION is an entirely different process...
      return $this->executeCancellation($match, $btx);
    }

    $contribution_id = $match->getParameter('contribution_id');
    $status_pending = banking_helper_optionvalue_by_groupname_and_name('contribution_status', 'Pending');
    $status_inprogress = banking_helper_optionvalue_by_groupname_and_name('contribution_status', 'In Progress');
    $status_completed = banking_helper_optionvalue_by_groupname_and_name('contribution_status', 'Completed');

    // unfortunately, we might have to do some fixes first...

    // FIX 1: fix contribution, if it has no financial transactions. (happens due to a status-bug in civicrm)
    //        in this case, set the status back to 'Pending', no 'is_pay_later'
    $fix_rotten_contribution_sql = "
    UPDATE 
      civicrm_contribution 
    SET 
      contribution_status_id=$status_pending, is_pay_later=0 
    WHERE 
        id = $contribution_id
    AND NOT (   SELECT count(entity_id) 
                FROM civicrm_entity_financial_trxn 
                WHERE entity_table='civicrm_contribution'
                AND   entity_id = $contribution_id 
            );";
    CRM_Core_DAO::executeQuery($fix_rotten_contribution_sql);

    // FIX 2: in CiviCRM pre 4.4.4, the status change 'In Progress' => 'Completed' was not allowed
    //        in this case, set the status back to 'Pending', no 'is_pay_later'
    if (CRM_Utils_System::version() < '4.4.4') {
      $fix_status_query = "
      UPDATE
          civicrm_contribution
      SET
          contribution_status_id = $status_pending,
          is_pay_later = 0
      WHERE 
          contribution_status_id = $status_inprogress
      AND id = $contribution_id;
      ";
      CRM_Core_DAO::executeQuery($fix_status_query);
    }

    // look up the txgroup
    $txgroup_query = civicrm_api('SepaContributionGroup', 'getsingle', array('contribution_id'=>$contribution_id, 'version'=>3));
    if (!empty($txgroup_query['is_error'])) {
      CRM_Core_Session::setStatus(ts("Contribution is NOT member in exactly one SEPA transaction group!"), ts('Error'), 'error');
      return;
    }
    $txgroup_id = $txgroup_query['txgroup_id'];

    // now we can set the status to 'Completed'
    $query = array('version' => 3, 'id' => $contribution_id);
    $query['contribution_status_id'] = $status_completed;
    $query['receive_date'] = date('Ymdhis', strtotime($btx->value_date));
    $query = array_merge($query, $this->getPropagationSet($btx, $match, 'contribution'));   // add propagated values
    $result = civicrm_api('Contribution', 'create', $query);

    if (isset($result['is_error']) && $result['is_error']) {
      error_log("org.project60.sepa: matcher_sepa: Couldn't modify contribution, error was: ".$result['error_message']);
      CRM_Core_Session::setStatus(ts("Couldn't modify contribution."), ts('Error'), 'error');

    } else {
      // everything seems fine, save the account
      if (!empty($result['values'][$contribution_id]['contact_id'])) {
        $this->storeAccountWithContact($btx, $result['values'][$contribution_id]['contact_id']);
      } elseif (!empty($result['values'][0]['contact_id'])) {
        $this->storeAccountWithContact($btx, $result['values'][0]['contact_id']);
      }

      // close transaction group if this was the last transaction
      $open_contributions_in_group_sql = "
      SELECT 
        count(c2group.id) AS open_count
      FROM 
        civicrm_sdd_contribution_txgroup c2group
      LEFT JOIN 
        civicrm_contribution contribution ON c2group.contribution_id=contribution.id
      WHERE 
          c2group.txgroup_id = $txgroup_id
      AND contribution.contribution_status_id IN ($status_pending,$status_inprogress);";
      $result = CRM_Core_DAO::executeQuery($open_contributions_in_group_sql);
      if ($result->fetch() && $result->open_count==0) {
        // set this group's status to 'received'
        $group_status_id_received = banking_helper_optionvalue_by_groupname_and_name('batch_status', 'Received');
        if ($group_status_id_received) {
          $txgroup_query = array('id'=>$txgroup_id, 'status_id'=>$group_status_id_received, 'version'=>3);
          $close_result = civicrm_api('SepaTransactionGroup', 'create', $txgroup_query);
          if (!empty($close_result['is_error'])) {
            CRM_Core_Session::setStatus(sprintf("Cannot mark transaction group [%s] received. Error: %s", $txgroup_id, $close_result['error_message']), ts('Error'), 'error');
            return;
          }
          $txgroup = civicrm_api('SepaTransactionGroup', 'getsingle', $txgroup_query);
          if (!empty($txgroup['is_error'])) {
            CRM_Core_Session::setStatus(sprintf("Cannot mark transaction group [%s] received. Error: %s", $txgroup_id, $txgroup['error_message']), ts('Error'), 'error');
            return;
          }
          CRM_Core_Session::setStatus(sprintf(ts("SEPA transaction group '%s' was marked as received."), $txgroup['reference']), ts('Success'), 'info');
        }
      }
    }

    $newStatus = banking_helper_optionvalueid_by_groupname_and_name('civicrm_banking.bank_tx_status', 'Processed');
    $btx->setStatus($newStatus);
    parent::execute($match, $btx);
    return true;
  }

  /**
   * Handle the different actions, should probably be handles at base class level ...
   * 
   * @param type $match
   * @param type $btx
   */
  public function executeCancellation($match, $btx) {
    $config = $this->_plugin_config;
    $contribution_id = $match->getParameter('contribution_id');
    $mandate_id = $match->getParameter('mandate_id');
    $status_cancelled = banking_helper_optionvalue_by_groupname_and_name('contribution_status', 'Cancelled');

    // set the status to 'Cancelled'
    $query = array('version' => 3, 'id' => $contribution_id);
    $query['contribution_status_id'] = $status_cancelled;
    $query['cancel_date'] = date('Ymdhis', strtotime($btx->value_date));
    $query = array_merge($query, $this->getPropagationSet($btx, $match, 'contribution', $config->cancellation_value_propagation));   // add propagated values
    if (empty($query['cancel_reason'])) // add default values
      $query['cancel_reason'] = $config->cancellation_default_reason;
    if ($config->cancellation_cancel_reason) {
      $query['cancel_reason'] = $match->getParameter('cancel_reason');
    }
    $result = civicrm_api('Contribution', 'create', $query);

    if (isset($result['is_error']) && $result['is_error']) {
      error_log("org.project60.sepa: matcher_sepa: Couldn't modify contribution, error was: ".$result['error_message']);
      CRM_Core_Session::setStatus(ts("Couldn't modify contribution."), ts('Error'), 'error');

    } else {
      // now for the mandate...
      $contribution = civicrm_api('Contribution', 'getsingle', array('version'=>3, 'id' => $contribution_id));
      if (!empty($contribution['is_error'])) {
        error_log("org.project60.sepa: matcher_sepa: Couldn't load contribution, error was: ".$result['error_message']);
        CRM_Core_Session::setStatus(ts("Couldn't modify contribution."), ts('Error'), 'error');
      } else {
        if ($contribution['contribution_payment_instrument'] != 'RCUR') {
          // everything seems fine, adjust the mandate's status
          $query = array('version' => 3, 'id' => $mandate_id);
          $query['status'] = 'INVALID';
          $query = array_merge($query, $this->getPropagationSet($btx, $match, 'mandate'));   // add propagated values
          $result = civicrm_api('SepaMandate', 'create', $query);
          if (!empty($result['is_error'])) {
            error_log("org.project60.sepa: matcher_sepa: Couldn't modify mandate, error was: ".$result['error_message']);
            CRM_Core_Session::setStatus(ts("Couldn't modify mandate."), ts('Error'), 'error');
          }
        }
      } 
    }

    // create activity if wanted
    if ($config->cancellation_create_activity) {
      // gather some information to put in the text
      $smarty = CRM_Core_Smarty::singleton();
      $smarty->assign('contribution', $contribution);
      $smarty->assign('cancel_fee', $match->getParameter('cancel_fee'));
      $smarty->assign('cancel_reason', $match->getParameter('cancel_reason'));
      
      // load the mandate
      $mandate = civicrm_api('SepaMandate', 'getsingle', array('id' => $mandate_id, 'version' => 3));
      $smarty->assign('mandate', $mandate);

      // load the contact
      $contact = civicrm_api('Contact', 'getsingle', array('id' => $contribution['contact_id'], 'version' => 3));
      $smarty->assign('contact', $contact);

      // count the cancelled contributions connected to this mandate
      $cancelled_contribution_count = 0;
      $current_contribution_date = date('Ymdhis', strtotime($contribution['receive_date']));
      if ($mandate['type']=='RCUR') {
        $query = "SELECT contribution_status_id
                  FROM civicrm_contribution
                  WHERE contribution_recur_id = {$mandate['entity_id']}
                    AND receive_date <= '$current_contribution_date'
                  ORDER BY receive_date DESC;";
        $status_list = CRM_Core_DAO::executeQuery($query);
        while ($status_list->fetch()) {
          if ($status_list->contribution_status_id == $status_cancelled) {
            $cancelled_contribution_count += 1;
          } else {
            break;
          }
        }
      }
      $smarty->assign('cancelled_contribution_count', $cancelled_contribution_count);

      // look up contact if not set
      $user_id = CRM_Core_Session::singleton()->get('userID');
      if (empty($config->cancellation_create_activity_assignee_id)) {
        $assignedTo = $user_id;
      } else {
        $assignedTo = (int) $config->cancellation_create_activity_assignee_id;
      }

      // compile the text
      if (empty($config->cancellation_create_activity_text)) {
        $details = $smarty->fetch('CRM/Banking/PluginImpl/Matcher/SepaMandate.activity.tpl');
      } else {
        $details = $smarty->fetch("string:" . $config->cancellation_create_activity_text);
      }
      
      $activity_parameters = array(
        'version'            => 3,
        'activity_type_id'   => $config->cancellation_create_activity_type_id,
        'subject'            => $config->cancellation_create_activity_subject,
        'status_id'          => 1, // planned
        'activity_date_time' => date('YmdHis'),
        'source_contact_id'  => $user_id,
        'target_contact_id'  => $contact['id'],
        'details'            => $details
      );
      $activity = CRM_Activity_BAO_Activity::create($activity_parameters);

      $assignment_parameters = array(
        'activity_id'    => $activity->id,
        'contact_id'     => $assignedTo,
        'record_type_id' => 1  // ASSIGNEE
      );
      $assignment = CRM_Activity_BAO_ActivityContact::create($assignment_parameters);
    }

    $newStatus = banking_helper_optionvalueid_by_groupname_and_name('civicrm_banking.bank_tx_status', 'Processed');
    $btx->setStatus($newStatus);
    parent::execute($match, $btx);
    return true;
  }

  /**
   * If the user has modified the input fields provided by the "visualize" html code,
   * the new values will be passed here BEFORE execution
   *
   * CAUTION: there might be more parameters than provided. Only process the ones that
   *  'belong' to your suggestion.
   */
  public function update_parameters(CRM_Banking_Matcher_Suggestion $match, $parameters) {
    $config = $this->_plugin_config;
    if ($match->getParameter('cancellation_mode')) {
      // store potentially modified extended cancellation values
      if ($config->cancellation_cancel_reason) {
        $match->setParameter('cancel_reason', $parameters['cancel_reason']);
      }
      if ($config->cancellation_cancel_fee) {
        $match->setParameter('cancel_fee', (float) $parameters['cancel_fee']);
      }
    }
  }

    /** 
   * Generate html code to visualize the given match. The visualization may also provide interactive form elements.
   * 
   * @val $match    match data as previously generated by this plugin instance
   * @val $btx      the bank transaction the match refers to
   * @return html code snippet
   */  
  function visualize_match( CRM_Banking_Matcher_Suggestion $match, $btx) {
    $config = $this->_plugin_config;
    $smarty = CRM_Core_Smarty::singleton();

    // load the contribution
    $contribution_id   = $match->getParameter('contribution_id');
    $mandate_id        = $match->getParameter('mandate_id');
    $mandate_reference = $match->getParameter('mandate_reference');
    $cancellation_mode = $match->getParameter('cancellation_mode');
    $cancellation_mode = !(empty($cancellation_mode));

    $smarty->assign('contribution_id', $contribution_id);
    $smarty->assign('mandate_id', $mandate_id);
    $smarty->assign('mandate_reference', $mandate_reference);
    $smarty->assign('cancellation_mode', $cancellation_mode);

    $result = civicrm_api('Contribution', 'get', array('version' => 3, 'id' => $contribution_id));
    if (isset($result['id'])) {
      // gather information
      $contribution = $result['values'][$result['id']];
      $contact_id   = $contribution['contact_id'];
      $contact_link = CRM_Utils_System::url("civicrm/contact/view", "reset=1&cid=$contact_id");
      $smarty->assign('contribution', $contribution);
      $smarty->assign('contact_id', $contact_id);
      $smarty->assign('contact_html', "<a href=\"$contact_link\" target=\"_blank\">{$contribution['display_name']} [$contact_id]</a>");
      $smarty->assign('mandate_link', CRM_Utils_System::url("civicrm/sepa/xmandate", "mid=$mandate_id"));
      $smarty->assign('contribution_link', CRM_Utils_System::url("civicrm/contact/view/contribution", "reset=1&id=$contribution_id&cid=$contact_id&action=view"));

      // add warnings, if any
      $smarty->assign('warnings', $match->getEvidence());

      // add cancellation extra parameters
      if ($cancellation_mode) {
        $smarty->assign('cancellation_cancel_reason', $config->cancellation_cancel_reason);
        if ($config->cancellation_cancel_reason) {
          $smarty->assign('cancel_reason',      $match->getParameter('cancel_reason'));
          $smarty->assign('cancel_reason_edit', $config->cancellation_cancel_reason_edit);
        }
        $smarty->assign('cancellation_cancel_fee', $config->cancellation_cancel_fee);
        if ($config->cancellation_cancel_fee) {
          $smarty->assign('cancel_fee',      $match->getParameter('cancel_fee'));
          $smarty->assign('cancel_fee_edit', $config->cancellation_cancel_fee_edit);
        }
      }

    } else {
      // CONTRIBUTION NOT FOUND!
      $smarty->assign('error', ts("Internal error! Cannot find contribution #").$match->getParameter('contribution_id'));
    }

    return $smarty->fetch('CRM/Banking/PluginImpl/Matcher/SepaMandate.suggestion.tpl');
  }

  /** 
   * Generate html code to visualize the executed match.
   * 
   * @val $match    match data as previously generated by this plugin instance
   * @val $btx      the bank transaction the match refers to
   * @return html code snippet
   */  
  function visualize_execution_info( CRM_Banking_Matcher_Suggestion $match, $btx) {
    // just assign to smarty and compile HTML
    $smarty = CRM_Core_Smarty::singleton();
    $smarty->assign('contribution_id',   $match->getParameter('contribution_id'));
    $contact_id = $match->getParameter('contact_id');
    if (empty($contact_id)) {
      // this information has not been stored (old matcher version)
      $result = civicrm_api('Contribution', 'get', array('version' => 3, 'id' => $match->getParameter('contribution_id')));
      if (isset($result['id'])) {
        $contribution = $result['values'][$result['id']];
        $contact_id   = $contribution['contact_id'];
        $smarty->assign('contact_id', $contact_id);
      } else {
        // TODO: error handling?
        $smarty->assign('contact_id', 1);
      }
    } else {
      $smarty->assign('contact_id', $contact_id);
    }
    $smarty->assign('cancellation_mode', $match->getParameter('cancellation_mode'));
    $smarty->assign('cancel_fee',        $match->getParameter('cancel_fee'));
    $smarty->assign('cancel_reason',     $match->getParameter('cancel_reason'));
    return $smarty->fetch('CRM/Banking/PluginImpl/Matcher/SepaMandate.execution.tpl');
  }
}

