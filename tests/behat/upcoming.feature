@local @local_cpdlog
Feature: Upcoming CPD on the logbook
  In order to plan my CPD
  As a member
  I need to see what is coming up in my CPD courses, and what other CPD is available

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | member1  | Member    | One      | member1@example.com |
    And the following "courses" exist:
      | fullname          | shortname | startdate      | customfield_cpdlog_hours |
      | Dermoscopy basics | DERM      | ##+10 days##   | 2.5                      |
      | Practice audit    | AUDIT     | ##+5 days##    | 1                        |
      | Unrelated course  | OTHER     | ##+5 days##    |                          |
    And the following "course enrolments" exist:
      | user    | course | role    |
      | member1 | DERM   | student |
      | member1 | OTHER  | student |
    And the following "events" exist:
      | name                | eventtype | course | timestart    |
      | Dermoscopy webinar  | course    | DERM   | ##+3 days##  |
      | Audit workshop      | course    | AUDIT  | ##+7 days##  |
      | Unrelated meeting   | course    | OTHER  | ##+2 days##  |

  Scenario: A member sees upcoming CPD in their own courses, and switches to what else is available
    Given I log in as "member1"
    When I visit "/local/cpdlog/index.php"
    Then I should see "Upcoming CPD"
    And I should see "Dermoscopy webinar" in the ".local-cpdlog-upcoming" "css_element"
    And I should see "2.50 CPD hours" in the ".local-cpdlog-upcoming" "css_element"
    And I should see "Dermoscopy basics" in the ".local-cpdlog-upcoming" "css_element"
    And I should not see "Audit workshop" in the ".local-cpdlog-upcoming" "css_element"
    And I should not see "Unrelated meeting"
    And I follow "Available"
    And I should see "Practice audit" in the ".local-cpdlog-upcoming" "css_element"
    And I should see "Course starts" in the ".local-cpdlog-upcoming" "css_element"
    And I should see "Audit workshop" in the ".local-cpdlog-upcoming" "css_element"
    And I should not see "Dermoscopy webinar" in the ".local-cpdlog-upcoming" "css_element"

  Scenario: Upcoming CPD can be switched off
    Given the following config values are set as admin:
      | upcomingenabled | 0 | local_cpdlog |
    When I log in as "member1"
    And I visit "/local/cpdlog/index.php"
    Then I should not see "Upcoming CPD"
