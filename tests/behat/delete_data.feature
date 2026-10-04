@local @local_cpdlog
Feature: Deleting a member's CPD data
  In order to act on a member's request to erase their CPD data
  As a site administrator
  I need to delete one member's CPD data, with a confirmation and a record of what was done

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | member1  | Member    | One      | member1@example.com |
    And the following "local_cpdlog > periods" exist:
      | name | firstday   | lastday    |
      | 2026 | 01/01/2026 | 31/12/2026 |
    And the following "local_cpdlog > entries" exist:
      | user    | period | day        | status   | evidence        |
      | member1 | 2026   | 10/03/2026 | draft    |                 |
      | member1 | 2026   | 11/03/2026 | approved | certificate.pdf |
      | member1 | 2026   | 12/03/2026 | approved |                 |

  Scenario: An administrator deletes a member's CPD data after typing their username
    Given the following config values are set as admin:
      | enabledeletion | 1 | local_cpdlog |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > CPD logbook > Delete member CPD data" in site administration
    And I should see "No deletions have been made."
    When I set the field "Username or email address" to "member1@example.com"
    And I press "Find member"
    Then I should see "Delete the CPD data of Member One"
    And I should see "Entries with status \"Approved\": 2"
    And I should see "Entries with status \"Draft\": 1"
    And I should see "Evidence files: 1"
    When I set the field "To confirm, type the username member1" to "member2"
    And I press "Delete CPD data"
    Then I should see "Type the member's username exactly to confirm."
    When I set the field "To confirm, type the username member1" to "member1"
    And I press "Delete CPD data"
    Then I should see "The CPD data of Member One will be deleted in the background within a few minutes."
    And I should see "Queued" in the "Member One" "table_row"
    When I run all adhoc tasks
    And I navigate to "Plugins > Local plugins > CPD logbook > Delete member CPD data" in site administration
    Then I should see "Done" in the "Member One" "table_row"
    And the following should exist in the "local-cpdlog-deletions" table:
      | Member     | Requested by | Status | Entries deleted | Files deleted |
      | Member One | Admin User   | Done   | 3               | 1             |
    And I log out
    And I log in as "member1"
    And I visit "/local/cpdlog/index.php"
    And I should see "You have not logged any CPD activities yet."

  Scenario: Deleting is refused while the tool is switched off
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > CPD logbook > Delete member CPD data" in site administration
    Then I should see "CPD data deletion is switched off."
    And "Username or email address" "field" should not exist

  Scenario: An administrator deletes the CPD data of a member whose Moodle account was already deleted
    Given the following "local_cpdlog > deleted accounts" exist:
      | user    |
      | member1 |
    And the following config values are set as admin:
      | enabledeletion | 1 | local_cpdlog |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > CPD logbook > Delete member CPD data" in site administration
    When I set the field "Username or email address" to "member1@example.com"
    And I press "Find member"
    Then I should see "No single active account has this username or email address."
    When I set the following fields to these values:
      | Username or email address  | member1@example.com |
      | Look for a deleted account | 1                   |
    And I press "Find member"
    Then I should see "Delete the CPD data of Member One"
    And I should see "This Moodle account has been deleted."
    And I should see "Entries with status \"Approved\": 2"
    When I set the field "To confirm, type the user ID" to "member1"
    And I press "Delete CPD data"
    Then I should see "Type the account's user ID exactly to confirm."
