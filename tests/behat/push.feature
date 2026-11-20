@local @local_cpdlog
Feature: Sending approved CPD to iMIS
  In order to keep members' CPD records in iMIS up to date
  As a staff member
  I need approved entries to be sent to iMIS, and to check them in a dry run first

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | idnumber |
      | ann      | Ann       | Archer   | 1001     |
    And the following "courses" exist:
      | fullname          | shortname |
      | Dermoscopy basics | DERM      |
    And the following "local_cpdlog > periods" exist:
      | name | firstday   | lastday    |
      | 2026 | 01/01/2026 | 31/12/2026 |
    And the following "local_cpdlog > entries" exist:
      | user | period | course | day        | category | hours | status    |
      | ann  | 2026   | DERM   | 10/03/2026 | EA       | 1.5   | submitted |
    And the following config values are set as admin:
      | imisbaseurl  | https://imis.example.org | local_cpdlog |
      | imisusername | moodle-service           | local_cpdlog |
      | imispassword | secret                   | local_cpdlog |

  Scenario: Approving an entry in a dry run records what would be sent to iMIS, and sends nothing
    Given the following config values are set as admin:
      | pushenabled | 1 | local_cpdlog |
    And I log in as "admin"
    And I visit "/local/cpdlog/admin/review.php"
    And I click on "Approve" "link" in the "10/03/2026" "table_row"
    And I press "Continue"
    And I should see "Entry approved."
    And I run all adhoc tasks
    When I navigate to "Plugins > Local plugins > CPD logbook > Send CPD to iMIS" in site administration
    Then I should see "Dry run is on"
    And I follow "Dry run (1)"
    And I should see "Dermoscopy basics" in the ".local-cpdlog-push" "css_element"
    And I should see "Create" in the ".local-cpdlog-push" "css_element"
    And I should see "\"OriginatingReferenceType\"" in the ".local-cpdlog-push" "css_element"
    And I should see "SCCAActivity" in the ".local-cpdlog-push" "css_element"
    And I press "Send again"
    And I should see "Entries queued to send again: 1."

  Scenario: Sending is off until switched on
    When I log in as "admin"
    And I navigate to "Plugins > Local plugins > CPD logbook > Send CPD to iMIS" in site administration
    Then I should see "Sending to iMIS is switched off"
    And I should see "No entries."
