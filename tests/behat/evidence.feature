@local @local_cpdlog
Feature: Evidence for CPD entries
  In order to prove I completed an activity
  As a member
  I need to attach evidence, and submit it where my category requires it

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | member1  | Member    | One      | member1@example.com |
    And the following "courses" exist:
      | fullname          | shortname |
      | Dermoscopy basics | DERM      |
    And the following "course enrolments" exist:
      | user    | course | role    |
      | member1 | DERM   | student |
    And the following "local_cpdlog > periods" exist:
      | name | firstday   | lastday    |
      | 2026 | 01/01/2026 | 31/12/2026 |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > CPD logbook > Categories" in site administration
    And I click on "Edit" "link" in the "Educational activities" "table_row"
    And I set the field "Evidence required" to "1"
    And I press "Save changes"
    And I log out

  Scenario: An entry needs evidence before it can be submitted, and its files are listed
    Given the following "local_cpdlog > entries" exist:
      | user    | period | course | day        | category | evidence        |
      | member1 | 2026   | DERM   | 10/03/2026 | EA       |                 |
      | member1 | 2026   | DERM   | 11/03/2026 | EA       | certificate.pdf |
    And I log in as "member1"
    When I visit "/local/cpdlog/index.php"
    Then I should see "Add evidence before submitting." in the "10/03/2026" "table_row"
    And "Submit" "link" should not exist in the "10/03/2026" "table_row"
    And "certificate.pdf" "link" should exist in the "11/03/2026" "table_row"
    And "Submit" "link" should exist in the "11/03/2026" "table_row"
    When I click on "Submit" "link" in the "11/03/2026" "table_row"
    And I press "Continue"
    Then I should see "Submitted for review" in the "11/03/2026" "table_row"

  Scenario: Save and submit is refused without evidence, but a draft can be saved
    Given I log in as "member1"
    And I visit "/local/cpdlog/index.php"
    When I press "Log CPD activity"
    And I set the following fields to these values:
      | Category            | Educational activities |
      | Course              | Dermoscopy basics      |
      | activitydate[day]   | 10                     |
      | activitydate[month] | March                  |
      | activitydate[year]  | 2026                   |
      | Hours               | 2                      |
    And I press "Save and submit for review"
    Then I should see "This category needs at least one evidence file before the entry can be submitted."
    When I press "Save draft"
    Then I should see "Draft saved."
    And I should see "Add evidence before submitting." in the "10/03/2026" "table_row"
