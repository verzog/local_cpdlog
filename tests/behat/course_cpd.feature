@local @local_cpdlog
Feature: Courses that award CPD on completion
  In order to know which courses add CPD to members' logbooks automatically
  As a staff member
  I need to see each course's CPD hours and category, and set them in the course settings

  Background:
    Given the following "courses" exist:
      | fullname          | shortname | customfield_cpdlog_hours |
      | Dermoscopy basics | DERM1     | 2.5                      |
      | Practice audit    | AUDIT1    | 0                        |
      | Unrelated course  | OTHER1    |                          |

  Scenario: Staff see the courses that award CPD, with their hours and category
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > CPD logbook > CPD courses" in site administration
    Then the following should exist in the "local-cpdlog-courses" table:
      | Course            | Hours | Category               | Entries created |
      | Dermoscopy basics | 2.50  | Educational activities | 0               |
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
      | Course         | Hours | Category              |
      | Practice audit | 1.50  | Reviewing performance |
