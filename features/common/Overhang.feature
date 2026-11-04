Feature: Overhang
  In order to load pallets the way they are really loaded
  As a developer
  I need items to be able to hang a little over the edges of a box that allows it

  Scenario: An item that only fits with the overhang is packed
    Given the box "Pallet", which has external dimensions 1000mm w × 1000mm l × 500mm d × 0g and internal dimensions 1000mm w × 1000mm l × 500mm d and has a max weight of 10000g and an allowed overhang of 50mm at the sides and 0mm at the front and back
    When I add 1 x keep flat "Board" with dimensions 1100mm w × 500mm l × 100mm d × 100g
    And I do a volume-only packing
    Then the packed box should have 1 items of type "Board"
    And the packed box's items should reach from -50mm to 1050mm across its width and from 0mm to 500mm along its length

  Scenario: The same item does not fit the box without the overhang
    Given the box "Pallet", which has external dimensions 1000mm w × 1000mm l × 500mm d × 0g and internal dimensions 1000mm w × 1000mm l × 500mm d and has a max weight of 10000g
    When I add 1 x keep flat "Board" with dimensions 1100mm w × 500mm l × 100mm d × 100g
    And I do a volume-only packing
    Then the packed box should have 0 items of type "Board"

  Scenario: Each item may only hang over by 15 % of its own size
    Given the box "Pallet", which has external dimensions 1000mm w × 1000mm l × 500mm d × 0g and internal dimensions 1000mm w × 1000mm l × 500mm d and has a max weight of 10000g and an allowed overhang of 300mm at the sides and 0mm at the front and back
    When I add 1 x keep flat "Board" with dimensions 1300mm w × 500mm l × 100mm d × 100g
    And I add 1 x keep flat "Plank" with dimensions 1500mm w × 500mm l × 100mm d × 100g
    And I do a volume-only packing
    Then the packed box should have 1 items of type "Board"
    And the packed box should have 0 items of type "Plank"

  Scenario: A pallet with overhang is chosen over a larger pallet when its overhang holds the load
    Given there is a box "Pallet with overhang", which has external dimensions 1000mm w × 1000mm l × 500mm d × 0g and internal dimensions 1000mm w × 1000mm l × 500mm d and has a max weight of 10000g and an allowed overhang of 50mm at the sides and 0mm at the front and back
    And there is a box "Larger pallet", which has external dimensions 1100mm w × 1000mm l × 500mm d × 0g and internal dimensions 1100mm w × 1000mm l × 500mm d and has a max weight of 10000g
    When I add 10 x keep flat "Board" with dimensions 1100mm w × 500mm l × 100mm d × 100g
    And I do a packing
    Then I should have 1 boxes of type "Pallet with overhang"
    And I should have 0 boxes of type "Larger pallet"

  Scenario: A load that hangs over one side is moved to hang over both sides equally
    Given the box "Pallet", which has external dimensions 1000mm w × 1000mm l × 500mm d × 0g and internal dimensions 1000mm w × 1000mm l × 500mm d and has a max weight of 10000g and an allowed overhang of 100mm at the sides and 0mm at the front and back
    When I add 1 x keep flat "Board" with dimensions 1050mm w × 500mm l × 100mm d × 100g
    And I do a volume-only packing
    Then the packed box should have 1 items of type "Board"
    And the packed box's items should reach from -25mm to 1025mm across its width and from 0mm to 500mm along its length
