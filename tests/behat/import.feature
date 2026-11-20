@local @local_cpdlog
Feature: Importing CPD history from another system
  In order to bring members' CPD history from iMIS or a file into their logbooks
  As a staff member
  I need to review an audit, import the new records, and decide on conflicts

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | idnumber |
      | ann      | Ann       | Archer   | 1001     |
    And the following "local_cpdlog > periods" exist:
      | name | firstday   | lastday    |
      | 2026 | 01/01/2026 | 31/12/2026 |
    And the following "local_cpdlog > entries" exist:
      | user | period | day        | category | hours | status   |
      | ann  | 2026   | 10/03/2026 | EA       | 2     | approved |
    And the following "local_cpdlog > import rows" exist:
      | user | key | day        | category | hours | title              | outcome   | reason   |
      | ann  | K1  | 05/02/2026 | EA       | 1.5   | Dermoscopy webinar | new       |          |
      | ann  | K2  | 10/03/2026 | EA       | 2     | Skin workshop      | conflict  |          |
      | ann  | K3  | 11/03/2026 | RP       | 1     | Practice audit     | conflict  |          |
      |      | K4  | 12/03/2026 | EA       | 1     | Unknown member     | unmatched | nomember |

  Scenario: Staff review an audit and import its new records into the member's logbook
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > CPD logbook > Import CPD history" in site administration
    Then I should see "Name the source's columns"
    And I click on ".local-cpdlog-importruns a" "css_element"
    And I should see "New (1)"
    And I should see "Unmatched (1)"
    And I should see "No single Moodle user has this member ID." in the "Unknown member" "table_row"
    And I press "Import 1 new records"
    And I should see "The new records will be imported shortly."
    And I should see "Import queued"
    And I run all adhoc tasks
    And I log out
    And I log in as "ann"
    And I visit "/local/cpdlog/index.php"
    And I should see "Dermoscopy webinar, Import (external)"
    And I should see "From an import"

  Scenario: Staff import a conflicting record anyway, or skip it
    Given I log in as "admin"
    When I visit "/local/cpdlog/admin/importconflicts.php"
    Then I should see "Skin workshop" in the ".local-cpdlog-importconflicts" "css_element"
    And I should see "Practice audit" in the ".local-cpdlog-importconflicts" "css_element"
    And I click on "Import anyway" "button" in the "Skin workshop" "table_row"
    And I should see "The record was imported."
    And I click on "Skip" "button" in the "Practice audit" "table_row"
    And I should see "The record will be skipped, now and in later audits."
    And I should see "No conflicts are waiting."

  Scenario: An audit of iMIS waits until the iMIS connection and IQA are set up
    Given the following config values are set as admin:
      | importmapmember | ID           | local_cpdlog |
      | importmapkey    | Ordinal      | local_cpdlog |
      | importmapdate   | ActivityDate | local_cpdlog |
      | importmaphours  | EA=Points    | local_cpdlog |
    And I log in as "admin"
    When I visit "/local/cpdlog/admin/import.php"
    Then I should not see "Name the source's columns"
    And I set the field "Source" to "iMIS"
    And I press "Run audit"
    And I should see "The iMIS connection or the import IQA is not set up."
