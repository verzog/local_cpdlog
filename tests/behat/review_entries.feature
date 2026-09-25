@local @local_cpdlog
Feature: Approving and rejecting CPD entries
  In order to accredit members' CPD
  As an approver
  I need to approve or reject the entries members submit

  Background:
    Given the following "users" exist:
      | username  | firstname | lastname | email                 |
      | member1   | Member    | One      | member1@example.com   |
      | approver1 | Approver  | One      | approver1@example.com |
    And the following "role assigns" exist:
      | user      | role    | contextlevel | reference |
      | approver1 | manager | System       |           |
    And the following "courses" exist:
      | fullname          | shortname |
      | Dermoscopy basics | DERM      |
    And the following "local_cpdlog > periods" exist:
      | name | firstday   | lastday    |
      | 2026 | 01/01/2026 | 31/12/2026 |
    And the following "local_cpdlog > entries" exist:
      | user      | period | course | day        | hours | status    | evidence        | description           | source |
      | member1   | 2026   | DERM   | 10/03/2026 | 2     | submitted | certificate.pdf | Dermoscopy workshop   | moodle |
      | member1   | 2026   | DERM   | 11/03/2026 | 3     | submitted |                 |                       | moodle |
      | approver1 | 2026   | DERM   | 12/03/2026 | 1     | submitted |                 |                       | moodle |
      | member1   | 2026   | DERM   | 13/03/2026 | 1     | submitted |                 | Imported from iMIS    | imis   |

  Scenario: An approver approves one entry and the member sees it approved
    Given I log in as "approver1"
    And I navigate to "Plugins > Local plugins > CPD logbook > Approval queue" in site administration
    Then "certificate.pdf" "link" should exist in the "10/03/2026" "table_row"
    And I should see "Dermoscopy workshop" in the "10/03/2026" "table_row"
    And I should not see "13/03/2026"
    And I should see "Your own entry. Another approver must review it." in the "12/03/2026" "table_row"
    And "Reject" "link" should not exist in the "12/03/2026" "table_row"
    When I click on "Approve" "link" in the "10/03/2026" "table_row"
    And I press "Continue"
    Then I should see "Entry approved. The member has been notified."
    And I should not see "10/03/2026"
    And I log out
    And I log in as "member1"
    And I visit "/local/cpdlog/index.php"
    And I should see "Approved" in the "10/03/2026" "table_row"

  Scenario: An approver rejects an entry with a reason and the member can edit it again
    Given I log in as "approver1"
    And I visit "/local/cpdlog/admin/review.php"
    When I click on "Reject" "link" in the "11/03/2026" "table_row"
    And I press "Reject"
    Then I should see "Required"
    When I set the field "Reason for rejection" to "Please attach your certificate."
    And I press "Reject"
    Then I should see "Entry rejected. The member has been notified."
    And I should not see "11/03/2026"
    And I log out
    And I log in as "member1"
    And I visit "/local/cpdlog/index.php"
    And I should see "Rejected" in the "11/03/2026" "table_row"
    And I should see "Reason for rejection: Please attach your certificate." in the "11/03/2026" "table_row"
    And "Edit" "link" should exist in the "11/03/2026" "table_row"

  Scenario: An approver approves several entries at once
    Given I log in as "approver1"
    And I visit "/local/cpdlog/admin/review.php"
    When I press "Approve selected"
    Then I should see "Select at least one entry to approve."
    When I set the field "Select the entry of Member One for 10/03/2026" to "1"
    And I set the field "Select the entry of Member One for 11/03/2026" to "1"
    And I press "Approve selected"
    Then I should see "Approve 2 selected entries?"
    When I press "Continue"
    Then I should see "2 entries approved."
    And I should not see "10/03/2026"
    And I should not see "11/03/2026"
    And I should see "12/03/2026"
