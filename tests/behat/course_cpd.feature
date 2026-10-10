@local @local_cpdlog
Feature: Releasing course completions to CPD logbooks
  In order to control which course completions count as CPD
  As an approver
  I need to release completions to members' logbooks from a checklist, or exclude some members

  Background:
    Given the following "courses" exist:
      | fullname          | shortname | customfield_cpdlog_hours |
      | Dermoscopy basics | DERM1     | 2.5                      |
      | Practice audit    | AUDIT1    | 0                        |
      | Unrelated course  | OTHER1    |                          |
    And the following "users" exist:
      | username | firstname | lastname |
      | ann      | Ann       | Archer   |
      | ben      | Ben       | Baker    |
      | cat      | Cat       | Carter   |
    And the following "local_cpdlog > periods" exist:
      | name | firstday   | lastday    |
      | 2026 | 01/01/2026 | 31/12/2026 |
    And the following "local_cpdlog > course completions" exist:
      | user | course | day        |
      | ann  | DERM1  | 10/03/2026 |
      | ben  | DERM1  | 11/03/2026 |
      | cat  | DERM1  | 12/03/2026 |
      | cat  | AUDIT1 | 12/03/2026 |

  Scenario: Staff see the courses that award CPD, with their hours, category and completions waiting
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > CPD logbook > CPD courses" in site administration
    Then the following should exist in the "local-cpdlog-courses" table:
      | Course            | Hours | Category               | Released | Waiting |
      | Dermoscopy basics | 2.50  | Educational activities | 0        | 3       |
    And I should not see "Practice audit"
    And I should not see "Unrelated course"

  Scenario: A manager sets a course's CPD hours and category in its settings
    Given I am on the "Practice audit" "course editing" page logged in as "admin"
    When I set the following fields to these values:
      | CPD hours    | 1.5                             |
      | CPD category | Reviewing performance (RP)      |
    And I press "Save and display"
    And I navigate to "Plugins > Local plugins > CPD logbook > CPD courses" in site administration
    Then the following should exist in the "local-cpdlog-courses" table:
      | Course         | Hours | Category              | Waiting |
      | Practice audit | 1.50  | Reviewing performance | 1       |

  Scenario: An approver excludes one member, releases the others, then releases the excluded member
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > CPD logbook > CPD courses" in site administration
    And I follow "Release completions"
    And the following should exist in the "local-cpdlog-waiting" table:
      | Full name  | Completed  |
      | Ann Archer | 10/03/2026 |
      | Ben Baker  | 11/03/2026 |
      | Cat Carter | 12/03/2026 |
    When I set the field "Ben Baker" to "1"
    And I press "Exclude"
    Then I should see "1 excluded."
    And the following should exist in the "local-cpdlog-excluded" table:
      | Full name | Excluded by |
      | Ben Baker | Admin User  |
    And I should not see "Ben Baker" in the ".local-cpdlog-waiting" "css_element"
    And I set the field "Ann Archer" to "1"
    And I set the field "Cat Carter" to "1"
    And I click on "Release to CPD logbooks" "button" in the "#waiting" "css_element"
    And I should see "2 released to CPD logbooks."
    And I should see "No completions are waiting to be released."
    And I set the field "Ben Baker" to "1"
    And I press "Release to CPD logbooks"
    And I should see "1 released to CPD logbooks."
    And I should not see "Excluded by"
    And I navigate to "Plugins > Local plugins > CPD logbook > CPD courses" in site administration
    And the following should exist in the "local-cpdlog-courses" table:
      | Course            | Released | Waiting |
      | Dermoscopy basics | 3        | 0       |

  Scenario: A completion outside any open reporting period cannot be ticked yet
    Given the following "local_cpdlog > course completions" exist:
      | user  | course | day        |
      | admin | DERM1  | 10/03/2025 |
    And I log in as "admin"
    When I navigate to "Plugins > Local plugins > CPD logbook > CPD courses" in site administration
    And I follow "Release completions"
    Then I should see "No open reporting period covers this date" in the "Admin User" "table_row"
    And I should not see "No open reporting period covers this date" in the "Ann Archer" "table_row"
