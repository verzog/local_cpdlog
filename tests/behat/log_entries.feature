@local @local_cpdlog
Feature: Members log CPD activities
  In order to record my continuing professional development
  As a member
  I need to log CPD activities against my courses and submit them for review

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | member1  | Member    | One      | member1@example.com |
    And the following "courses" exist:
      | fullname          | shortname |
      | Dermoscopy basics | DERM      |
      | Not my course     | OTHER     |
    And the following "course enrolments" exist:
      | user    | course | role    |
      | member1 | DERM   | student |
    And the following "local_cpdlog > periods" exist:
      | name | firstday   | lastday    |
      | 2026 | 01/01/2026 | 31/12/2026 |
    And the following config values are set as admin:
      | maxhoursperentry | 10 | local_cpdlog |
    And I log in as "member1"

  Scenario: Log a draft, then submit it for review
    Given I visit "/local/cpdlog/index.php"
    And I should see "You have not logged any CPD activities yet."
    When I press "Log CPD activity"
    Then the "Course" select box should not contain "Not my course"
    And I set the following fields to these values:
      | Category            | Educational activities |
      | Course              | Dermoscopy basics      |
      | activitydate[day]   | 10                     |
      | activitydate[month] | March                  |
      | activitydate[year]  | 2026                   |
      | Hours               | 1.5                    |
      | Description         | Dermoscopy workshop    |
    And I press "Save draft"
    Then I should see "Draft saved."
    And I should see "10/03/2026" in the "Dermoscopy basics" "table_row"
    And I should see "1.50" in the "Dermoscopy basics" "table_row"
    And I should see "Draft" in the "Dermoscopy basics" "table_row"
    When I click on "Submit" "link" in the "Dermoscopy basics" "table_row"
    Then I should see "Submit this entry for review?"
    And I press "Continue"
    And I should see "Entry submitted for review."
    And I should see "Submitted for review" in the "Dermoscopy basics" "table_row"
    And "Edit" "link" should not exist in the "Dermoscopy basics" "table_row"

  Scenario: The logging rules are explained on the form
    Given I visit "/local/cpdlog/index.php"
    When I press "Log CPD activity"
    And I set the following fields to these values:
      | Category            | Educational activities |
      | Course              | Dermoscopy basics      |
      | activitydate[day]   | 10                     |
      | activitydate[month] | March                  |
      | activitydate[year]  | 2025                   |
      | Hours               | 12                     |
    And I press "Save draft"
    Then I should see "No reporting period covers this date."
    And I should see "An entry can claim at most 10.00 hours."
    And I should not see "Draft saved."

  Scenario: Revise a rejected entry
    Given the following "local_cpdlog > entries" exist:
      | user    | period | course | day        | hours | status   | rejectionreason     |
      | member1 | 2026   | DERM   | 05/02/2026 | 3     | rejected | Add the certificate |
    And I visit "/local/cpdlog/index.php"
    And I should see "Reason for rejection: Add the certificate" in the "Dermoscopy basics" "table_row"
    And "Delete" "link" should not exist in the "Dermoscopy basics" "table_row"
    When I click on "Edit" "link" in the "Dermoscopy basics" "table_row"
    And I set the field "Hours" to "2"
    And I press "Save draft"
    Then I should see "Draft" in the "Dermoscopy basics" "table_row"
    And I should see "2.00" in the "Dermoscopy basics" "table_row"

  Scenario: A second entry for the same course and day gets a warning, and drafts can be deleted
    Given the following "local_cpdlog > entries" exist:
      | user    | period | course | day        | hours |
      | member1 | 2026   | DERM   | 10/03/2026 | 1     |
    And I visit "/local/cpdlog/index.php"
    When I press "Log CPD activity"
    And I set the following fields to these values:
      | Category            | Measuring outcomes |
      | Course              | Dermoscopy basics  |
      | activitydate[day]   | 10                 |
      | activitydate[month] | March              |
      | activitydate[year]  | 2026               |
      | Hours               | 2                  |
    And I press "Save draft"
    Then I should see "You already have an entry for this course on this date."
    When I click on "Delete" "link" in the "Measuring outcomes" "table_row"
    And I press "Continue"
    Then I should see "Draft entry deleted."
    And I should not see "Measuring outcomes"
