@local @local_cpdlog
Feature: Logging CPD activities outside Moodle
  In order to record conferences and workshops that are not Moodle courses
  As a member
  I need to log external activities in categories that accept them, with evidence, for approval

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | member1  | Member    | One      | member1@example.com |
    And the following "local_cpdlog > periods" exist:
      | name | firstday   | lastday    |
      | 2026 | 01/01/2026 | 31/12/2026 |

  Scenario: A member logs an external activity after staff allow them in a category
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > CPD logbook > Categories" in site administration
    And I click on "Edit" "link" in the "Educational activities" "table_row"
    And I set the field "Allows external activities" to "1"
    And I press "Save changes"
    And I log out
    When I log in as "member1"
    And I visit "/local/cpdlog/index.php"
    And I press "Log CPD activity"
    And I set the following fields to these values:
      | Category            | Educational activities                   |
      | Course              | External activity (not a Moodle course)  |
      | Activity name       | Dermoscopy conference                    |
      | Provider            | Australasian College of Dermatologists   |
      | activitydate[day]   | 10                                       |
      | activitydate[month] | March                                    |
      | activitydate[year]  | 2026                                     |
      | Hours               | 6                                        |
    And I press "Save and submit"
    Then I should see "External activities need at least one evidence file"
    And I press "Save draft"
    And I should see "Draft saved."
    And I should see "Dermoscopy conference, Australasian College of Dermatologists (external)" in the "10/03/2026" "table_row"

  Scenario: A category that does not accept external activities refuses them
    Given I log in as "member1"
    And I visit "/local/cpdlog/edit.php"
    Then the "Course" select box should not contain "External activity (not a Moodle course)"
