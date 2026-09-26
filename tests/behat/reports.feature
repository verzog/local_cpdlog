@local @local_cpdlog
Feature: CPD reports for staff
  In order to monitor members' CPD
  As CPD staff
  I need starting reports of members' progress and entries

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | member1  | Member    | One      | member1@example.com |
      | member2  | Member    | Two      | member2@example.com |
      | staff1   | Staff     | One      | staff1@example.com  |
    And the following "role assigns" exist:
      | user   | role    | contextlevel | reference |
      | staff1 | manager | System       |           |
    And the following "courses" exist:
      | fullname          | shortname |
      | Dermoscopy basics | DERM      |
    And the following "local_cpdlog > periods" exist:
      | name | firstday   | lastday    |
      | 2026 | 01/01/2026 | 31/12/2026 |
    And the following "local_cpdlog > targets" exist:
      | period | name      | requiredhours |
      | 2026   | Total CPD | 2             |
    And the following "local_cpdlog > entries" exist:
      | user    | period | course | day        | category | hours | status    |
      | member1 | 2026   | DERM   | 10/03/2026 | EA       | 2     | approved  |
      | member2 | 2026   | DERM   | 11/03/2026 | RP       | 1.5   | approved  |
      | member2 | 2026   | DERM   | 12/03/2026 | RP       | 1     | submitted |

  Scenario: CPD staff open the starting progress and entries reports
    Given I log in as "staff1"
    When I am on the "CPD progress by member" "reportbuilder > View" page
    Then the following should exist in the "reportbuilder-table" table:
      | Full name with link | Target    | Required hours | Approved hours | Hours waiting for review | Met |
      | Member One          | Total CPD | 2.00           | 2.00           | 0.00                     | Yes |
      | Member Two          | Total CPD | 2.00           | 1.50           | 1.00                     | No  |
    When I am on the "CPD entries" "reportbuilder > View" page
    Then the following should exist in the "reportbuilder-table" table:
      | Full name with link | Activity date | Hours | Status               |
      | Member One          | 10/03/2026    | 2.00  | Approved             |
      | Member Two          | 12/03/2026    | 1.00  | Submitted for review |
