@local @local_cpdlog
Feature: Reporting period dates in the calendar
  In order to know when my CPD is due
  As a member
  I need to see when each reporting period opens and closes in the Moodle calendar

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | member1  | Member    | One      | member1@example.com |
    And the following "local_cpdlog > periods" exist:
      | name    | firstday                  | lastday                       |
      | Current | ##yesterday##%d/%m/%Y##   | ##+10 days##%d/%m/%Y##        |

  Scenario: A member sees the closing date of the current period in their upcoming events
    When I log in as "member1"
    And I visit "/calendar/view.php?view=upcoming"
    Then I should see "CPD reporting period Current closes"
    And I should not see "CPD reporting period Current opens"
