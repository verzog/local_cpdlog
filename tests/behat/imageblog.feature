@local @local_cpdlog
Feature: CPD hours from the image blog
  In order to have all my CPD in one logbook
  As a member
  I need the CPD hours I earn on image blog clinical cases to appear in my logbook

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | member1  | Member    | One      | member1@example.com |
    And the following "local_cpdlog > periods" exist:
      | name | firstday   | lastday    |
      | 2026 | 01/01/2026 | 31/12/2026 |
    And the following "local_cpdlog > targets" exist:
      | period | name      | requiredhours |
      | 2026   | Total CPD | 10            |
    And the following "local_cpdlog > image blog awards" exist:
      | user    | case                 | reason        | hours | day        |
      | member1 | Pigmented lesion     | participation | 1.5   | 15/03/2026 |
      | member1 | Pigmented lesion     | bestanswer    | 0.5   | 15/03/2026 |
      | member1 | Scaly plaque on shin | view          | 0.25  | 20/03/2026 |

  Scenario: Image blog awards are copied into the member's logbook as approved hours
    Given I run the scheduled task "\local_cpdlog\task\sync_imageblog"
    When I log in as "member1"
    And I visit "/local/cpdlog/index.php"
    Then I should see "2.25" in the ".local-cpdlog-progress" "css_element"
    And the following should exist in the "local-cpdlog-entries" table:
      | Activity date | Course     | Hours | Status   |
      | 15/03/2026    | Image blog | 1.50  | Approved |
      | 15/03/2026    | Image blog | 0.50  | Approved |
      | 20/03/2026    | Image blog | 0.25  | Approved |
    And I should see "From the image blog" in the "0.25" "table_row"
    And "Edit" "link" should not exist in the "0.25" "table_row"

  Scenario: Copying can be switched off
    Given the following config values are set as admin:
      | imageblogenabled | 0 | local_cpdlog |
    And I run the scheduled task "\local_cpdlog\task\sync_imageblog"
    When I log in as "member1"
    And I visit "/local/cpdlog/index.php"
    Then I should see "You have not logged any CPD activities yet."
