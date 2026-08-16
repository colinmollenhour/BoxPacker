BoxPacker
=========

[![Build Status](https://github.com/dvdoug/BoxPacker/workflows/CI/badge.svg?branch=master)](https://github.com/dvdoug/BoxPacker/actions?query=workflow%3ACI+branch%3Amaster)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/dvdoug/BoxPacker/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/dvdoug/BoxPacker/?branch=master)
[![Download count](https://img.shields.io/packagist/dt/dvdoug/boxpacker.svg)](https://packagist.org/packages/dvdoug/boxpacker)
[![Current version](https://img.shields.io/packagist/v/dvdoug/boxpacker.svg)](https://packagist.org/packages/dvdoug/boxpacker)
[![Documentation](https://readthedocs.org/projects/boxpacker/badge/?version=stable)](https://www.boxpacker.io/en/stable/)

An implementation of the 3D bin packing problem with weight as a fourth constraint: given items and a catalogue of
boxes, how many cartons do you need, and what goes in each?

The same engine can also fill a single container (pallet, trailer, ocean box) as densely as possible and leave the rest
unpacked.

Typical uses: e-commerce cartonization and shipping-cost calculation, warehouse box selection, and one-container load
planning.

* Your own Item/Box objects (no wrapper DTOs)
* Rotation: any way up, keep-flat, or none
* Weight limits and automatic weight balancing across cartons
* Limited box stock, linked items, custom placement rules
* Packed x/y/z coordinates and a 3D visualiser

Requires PHP 8.2+. `composer require dvdoug/boxpacker`

See [documentation](https://boxpacker.io/) for more details.

License
-------
BoxPacker is MIT-licensed. 
