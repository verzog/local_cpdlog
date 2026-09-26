@local @local_cpdlog
Feature: CPD progress against targets
  In order to know whether I have done enough CPD
  As a member
  I need to see my approved and pending hours against my targets

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | member1  | Member    | One      | member1@example.com |
    And the following "courses" exist:
      | fullname          | shortname |
      | Dermoscopy basics | DERM      |
    And the following "local_cpdlog > periods" exist:
      | name | firstday   | lastday    |
      | 2025 | 01/01/2025 | 31/12/2025 |
      | 2026 | 01/01/2026 | 31/12/2026 |
    And the following "local_cpdlog > targets" exist:
      | period | name                     | requiredhours | categories | sortorder |
      | 2026   | Total CPD                | 50            |            | 1         |
      | 2026   | Reviewing and measuring  | 5             | RP, MO     | 2         |
      | 2026   | Educational activities   | 2             | EA         | 3         |
    And the following "local_cpdlog > entries" exist:
      | user    | period | course | day        | category | hours | status    |
      | member1 | 2026   | DERM   | 10/03/2026 | EA       | 2     | approved  |
      | member1 | 2026   | DERM   | 11/03/2026 | RP       | 3     | approved  |
      | member1 | 2026   | DERM   | 12/03/2026 | MO       | 1.5   | submitted |
      | member1 | 2026   | DERM   | 13/03/2026 | MO       | 4     | draft     |
      | member1 | 2026   | DERM   | 14/03/2026 | RP       | 8     | reversed  |

  Scenario: A member sees approved and pending hours against each target
    Given I log in as "member1"
    # 2026 is the current period, or the latest to have started once 2026 is over.
    When I visit "/local/cpdlog/index.php"
    Then I should see "Progress for 2026"
    And I should see "5.00 hours approved in this period, and 1.50 hours waiting for review."
    And I should see "5.00 of 50.00 hours approved"
    And I should see "3.00 of 5.00 hours approved"
    And I should see "1.50 more hours waiting for review"
    And I should see "2.00 of 2.00 hours approved"
    And I should see "Met"
    When I set the field "Reporting period" to "2025"
    And I press "Go"
    Then I should see "Progress for 2025"
    And I should see "0.00 hours approved in this period."
    And I should see "No CPD targets apply to you in this period."
