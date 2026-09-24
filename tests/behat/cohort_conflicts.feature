@local @local_cpdlog
Feature: Resolve cohort target conflicts
  In order to measure each member against the right CPD targets
  As a manager
  I need to choose which cohort's targets apply to members in more than one cohort

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | member1  | Member    | One      | member1@example.com |
    And the following "cohorts" exist:
      | name       | idnumber |
      | Fellows    | FEL      |
      | Registrars | REG      |
    And the following "cohort members" exist:
      | user    | cohort |
      | member1 | FEL    |
      | member1 | REG    |
    And the following "local_cpdlog > periods" exist:
      | name | firstday   | lastday    |
      | 2026 | 01/01/2026 | 31/12/2026 |
    And the following "local_cpdlog > targets" exist:
      | period | name              | requiredhours | cohort | categories |
      | 2026   | Total             | 50            |        |            |
      | 2026   | Fellows audit     | 10            | FEL    | MO         |
      | 2026   | Registrars review | 20            | REG    | RP         |
    And I log in as "admin"

  Scenario: Choose which cohort's targets to add for a member in two cohorts
    Given I navigate to "Plugins > Local plugins > CPD logbook > Reporting periods" in site administration
    And I should see "1" in the "2026" "table_row"
    When I click on "3" "link" in the "2026" "table_row"
    And I press "Cohort conflicts (1 unresolved)"
    Then I should see "Unresolved" in the "Member One" "table_row"
    And I should see "Fellows, Registrars" in the "Member One" "table_row"
    And I should see "Not chosen yet" in the "Member One" "table_row"
    When I set the field "Cohort targets to add for Member One" to "Registrars"
    And I click on "Save" "button" in the "Member One" "table_row"
    Then I should see "Resolved" in the "Member One" "table_row"
    And I should not see "Unresolved" in the "Member One" "table_row"
    And I should see "Registrars" in the "Member One" "table_row"
    When I set the field "Cohort targets to add for Member One" to "No cohort targets (all-members targets only)"
    And I click on "Save" "button" in the "Member One" "table_row"
    Then I should see "No cohort targets (all-members targets only)" in the "Member One" "table_row"
    And I press "Back to targets"
    And "Cohort conflicts (0 unresolved)" "button" should exist

  Scenario: Saving without choosing shows an error
    Given I navigate to "Plugins > Local plugins > CPD logbook > Reporting periods" in site administration
    And I click on "3" "link" in the "2026" "table_row"
    And I press "Cohort conflicts (1 unresolved)"
    When I click on "Save" "button" in the "Member One" "table_row"
    Then I should see "Choose which cohort targets to add before saving."
    And I should see "Unresolved" in the "Member One" "table_row"
