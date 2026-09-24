@local @local_cpdlog
Feature: Manage CPD categories, periods and targets
  In order to set CPD requirements
  As a manager
  I need to maintain categories, reporting periods and targets

  Background:
    Given I log in as "admin"

  Scenario: Add a category alongside the starting categories
    Given I navigate to "Plugins > Local plugins > CPD logbook > Categories" in site administration
    And I should see "Educational activities"
    And I should see "Reviewing performance"
    And I should see "Measuring outcomes"
    When I press "Add category"
    And I set the following fields to these values:
      | Name       | Clinical audit |
      | Short name | CA             |
    And I press "Save changes"
    Then I should see "Clinical audit"
    And I should see "CA"

  Scenario: Add a period, reject an overlapping one and add a combined target
    Given I navigate to "Plugins > Local plugins > CPD logbook > Reporting periods" in site administration
    When I press "Add period"
    And I set the following fields to these values:
      | Name             | 2026     |
      | startdate[day]   | 1        |
      | startdate[month] | January  |
      | startdate[year]  | 2026     |
      | lastday[day]     | 31       |
      | lastday[month]   | December |
      | lastday[year]    | 2026     |
    And I press "Save changes"
    Then I should see "01/01/2026"
    And I should see "31/12/2026"
    When I press "Add period"
    And I set the following fields to these values:
      | Name             | Overlap |
      | startdate[day]   | 31      |
      | startdate[month] | December |
      | startdate[year]  | 2026    |
      | lastday[day]     | 30      |
      | lastday[month]   | June    |
      | lastday[year]    | 2027    |
    And I press "Save changes"
    Then I should see "This period overlaps another period."
    And I press "Cancel"
    When I click on "0" "link" in the "2026" "table_row"
    And I press "Add target"
    And I set the following fields to these values:
      | Name               | Reviewing performance and measuring outcomes |
      | Reviewing performance | 1                                         |
      | Measuring outcomes    | 1                                         |
      | Required hours     | 25                                           |
    And I press "Save changes"
    Then I should see "Reviewing performance, Measuring outcomes" in the "Reviewing performance and measuring outcomes" "table_row"
    And I should see "All members" in the "Reviewing performance and measuring outcomes" "table_row"
    And I should see "25.00" in the "Reviewing performance and measuring outcomes" "table_row"

  Scenario: Closing a period locks its targets until it is reopened
    Given I navigate to "Plugins > Local plugins > CPD logbook > Reporting periods" in site administration
    And I press "Add period"
    And I set the following fields to these values:
      | Name             | 2027     |
      | startdate[day]   | 1        |
      | startdate[month] | January  |
      | startdate[year]  | 2027     |
      | lastday[day]     | 31       |
      | lastday[month]   | December |
      | lastday[year]    | 2027     |
    And I press "Save changes"
    And I click on "0" "link" in the "2027" "table_row"
    And I press "Add target"
    And I set the following fields to these values:
      | Name                   | Educational activities |
      | Educational activities | 1                      |
      | Required hours         | 12.5                   |
    And I press "Save changes"
    And I press "Back to periods"
    When I click on "Close" "link" in the "2027" "table_row"
    And I press "Continue"
    Then I should see "Closed" in the "2027" "table_row"
    And I click on "1" "link" in the "2027" "table_row"
    And I should see "This period is closed, so its targets cannot be changed."
    And "Add target" "button" should not exist
    And I press "Back to periods"
    When I click on "Reopen" "link" in the "2027" "table_row"
    And I press "Continue"
    Then I should see "Open" in the "2027" "table_row"
    And I click on "1" "link" in the "2027" "table_row"
    And I click on "Edit" "link" in the "Educational activities" "table_row"
    And the field "Educational activities" matches value "1"
    And the field "Measuring outcomes" matches value "0"
