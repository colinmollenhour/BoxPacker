# Soft packing: envelopes, mailers, bags (and a few ideas from outside the box)

Status: research + design proposal. Nothing here is implemented in `src/`. All numbers about carriers are from secondary
sources (primary pages for USPS/arXiv were unreachable from the research sandbox) and **must be verified before being
used as shipped defaults**. See "Sources" and "Risks".

## TL;DR

1. Soft packs differ from boxes in exactly two ways: **the container's inner shape depends on how full it is**, and
   **soft items have several valid shapes** (folded, rolled, compressed). Everything else (carrier thickness bands,
   protection levels, burst risk) is a constraint or a cost on top of those two.
2. Both can be *lowered* onto the existing rigid solvers: a mailer filled to thickness `t` is a rigid box of
   `(flatW - 2·seam - κ·t) × (flatL - flap - κ·t) × t`; an item with N states is one item with the union of the
   states' orientations. No new packing core is needed for v1; a spike (below) ran the unmodified `VolumePacker`.
3. No public "soft-pack cartonization" algorithm exists. Literature is robotics/RL (heavy, not PHP-shaped); vendors
   advertise "envelope routing" with no detail. The one directly relevant paper is Cainiao's *flexible bin* problem
   (minimise wrapper surface area). That is an opening, not a gap in our reading.
4. The differentiators worth building: fold/roll/compress **states** with worker-facing instructions, **carrier
   threshold-aware cost** (stay under 25 mm / under a L+W tier), **uncertainty-aware fit** with a self-calibrating loop,
   **right-sized/parametric containers**, and **explainable rejections**.

## 1. Soft-pack taxonomy

| Type | Typical spec | Geometry behaviour | What constrains it |
|---|---|---|---|
| Poly mailer | LDPE, 1.5-4 mil (2.5 standard for apparel); self-seal flap; sizes like 10x13in, 12x15.5in | Two sheets sealed at edges; fills into a pillow; plan size *shrinks* as thickness grows; flap/seam eat 1-2in of length | 2 mil ≤ ~5 lb, 4 mil ≤ ~10 lb; no sharp corners; not waterproof-critical goods in paper |
| Bubble / padded mailer | Poly or kraft outer, poly bubble (7/64in poly vs 3/16in kraft); numbered #000 (4x8in) to #7 (14.25x20in) | Like poly mailer but stiffer; usable size is smaller than listed | Stated sizes are outer; usable ≈ listed minus flap/seams |
| Paper / kraft envelope, rigid ("stay flat") mailer, Tyvek | Fixed small depth, often expandable (gusset) | Behaves like a very flat rigid box | "Do not bend" goods; thickness bands |
| Carrier-supplied padded envelope | e.g. USPS Priority Flat Rate Padded 12.5x9.5in | Fixed outer, price independent of weight | Weight cap, items must fit closed |
| Poly bag (open end / gusseted) | Used *inside* another container; Amazon: ≥1.5 mil, transparent, suffocation warning if opening ≥5in, bag ≤3in beyond product | Item-wrapping; adds ~thickness and a little weight | Marketplace rules |
| Vacuum / compression bag | Bedding, pillows, down; vendors claim 75-80% volume reduction | Volume = f(pressure); becomes a brick | Vendor claims, seal failure rate rises when overstuffed |
| Stretch / shrink wrap, "flexible bin" | Cainiao: ~5% of its packages are plastic-wrapped; cost ∝ wrapper surface area | Container dims are a *decision*, not a given | Surface area / material |
| Custom right-size (on-demand box or bag) | Machine makes a container to measured size within min/max | Dims are decision variables | Machine envelope, cost function |

## 2. Rules that drive the model (verify; they change)

| Source | Rule (as reported) | Modelling consequence |
|---|---|---|
| Royal Mail | Large Letter ≤353×250×**25 mm**, ≤750 g; Small Parcel ≤450×350×160 mm, ≤2 kg. 1 mm over 25 mm = parcel pricing (often >50% dearer) | Hard *thickness cap* on the filled pack; threshold-hunting cost |
| USPS Soft Pack Cubic | Tier by **length + width of the flat, unloaded** pack (rounded down to 0.25in); ≤20 lb; not folded; ≤18×18in (cap reported to rise to 22in in July 2026; Ground Advantage Cubic softpack tiers cut 10 → 5) | Measurement mode = *flat nominal*, not filled. Thresholds are data |
| UPS / FedEx | Dim weight measured at the outermost extent incl. bulge; each dimension rounded **up** to the next whole inch (from Aug 2025, per secondary source); advice to add 1-2in for poly bags | Measurement mode = *filled bounding box + bulge allowance* |
| USPS letters/flats | Items >1 oz and >¾in thick after packing are packages, not envelopes | Thickness/weight band classifier |
| Amazon poly bag | See taxonomy | Bag-in-box overpack rules |

So the library needs **measurement modes** (`FlatUnloaded`, `FilledMax`) and **rate profiles as data**, never hard-coded.

## 3. What the algorithm literature gives us

| Problem | Technique | Fit for this library |
|---|---|---|
| Flat goods in an envelope | 2D rectangle packing: maximal rectangles, skyline, guillotine | Already covered: `ThoroughPacker`/`BlockPacker` use maximal free spaces; envelope = rigid box with small depth |
| Thickness consuming width/length | Perimeter ("wrap") constraint, derived below | New, trivial, spike-validated |
| Item has alternative geometries | Multiple-choice packing (item variants): pick one variant per item | Natural in a beam search that already picks an orientation per item |
| Rolled goods | Circle packing of the cross-section: hex 0.9069 vs square 0.7854 density (+15.5%) | Hex *block* in the block builder, like the angled-nesting block |
| Compressible goods | Compression curve / ladder of states; vacuum as a bundling op | Preprocessing + states |
| Container dims are variables | "Normal patterns" (Christofides-Whitlock; Herz): optimal dims are subset sums of item dims, so candidate sizes are finite | Right-size containers, thickness candidates |
| Min-surface flexible wrapper | Cainiao 3D-FBPP; RL "selected learning" beat their production greedy by 5.47% | Parametric container with surface-area cost; greedy+normal patterns first |
| Uncertain item dims | Chance-constrained fit; independent variances add as root-sum-square, so padding < sum of worst cases | `setFitConfidence()` + calibrator |
| True deformation | Physics sim / SDF / RL (OPA-Pack, SDF-Pack, dynamics-based) | Too heavy to ship; use **offline** to calibrate κ and compression curves |

## 4. Core model

### 4.1 Mailer as a rigid box at fill thickness `t`

Two sheets of width `W` sealed at both side seams `s`. Cross-section perimeter `2(W-2s)`. A block `w × t` needs
`2(w+t)`, so `w + t ≤ W - 2s`. Along length, one end is the flap/seam (`f`): `l + t ≤ L - f`. Hence

```
inner = (W - 2s - κ·t) × (L - f - κ·t) × t        κ = 1 for a rigid block, lower for soft contents
```

A fully compliant lens-shaped fill needs only `c + (2/3)·t²/c` of sheet for chord `c` (230 mm chord, 40 mm thick:
4.6 mm, not 40 mm). Real garment stacks sit between: **κ is a calibrated per-container/per-product-class parameter,
default 1.0 (conservative)**. Hypothesis for soft stacks: 0.5-0.8, to be measured.

Feasibility is **not monotonic in `t`** (footprint shrinks as depth grows), so do not binary search. Sweep candidate
thicknesses = distinct stack heights (subset sums of item/state depths, clamped to `[tMin, tMax]`).

Consequences: filled plan size ≈ inner footprint + seams (carriers measuring filled dims see this, USPS soft pack sees
the flat dims). Both are reported.

### 4.2 Items with states

`ItemState { name, width, length, depth, kind (Block|Cylinder), effort, thicknessSigma, extraWeight }`. An item's
orientations = union over its states' orientations (each item is still placed once, so no combinatorial blow-up in
beam search or layer packer). The chosen state is recorded on `PackedItem` so the pack station gets "fold in thirds"
or "roll, band at both ends".

### 4.3 Spike (unmodified `VolumePacker`, 0.2 s for the whole sweep)

Mailer catalogue 250x330, 305x394, 356x457, 419x482 mm; tee folds `fold3 230×300×18`, `fold4 230×150×36`,
`roll 70×230×70`; jeans `fold3 250×320×35`, `fold4 250×160×70`; cap 60 mm unless noted. Picks the smallest flat area.

| Order | fold3-only | state-aware | Note |
|---|---|---|---|
| 1 tee | 305×394 @20 mm | **250×330 @40 mm (fold4)** | 31% less mailer area |
| 2 tees | 305×394 @40 | same | |
| 3 tees, cap 90 | 356×457 @55 | **305×394 @70 (rolled)** | 26% less area, thicker: needs a cost function to arbitrate |
| 2 tees + jeans, cap 60, κ=1 | no fit | no fit | exceeds cap, goes to a box |
| 2 tees + jeans, cap 60, κ=0.5 | no fit | **419×482 @55** | κ flips feasibility: calibrate it |
| 2 tees + jeans, cap 90, κ=1 | 356×457 @75 | 356×457 @70 (rolled tees + jeans fold4) | |

## 5. Feature catalogue

Style: opt-in like `setAllowAngledPlacement()`; new interfaces *extend* `Item`/`Box` (precedent: `LimitedSupplyBox`,
`LinkedItem`, `ConstrainedPlacementItem`), so 4.x stays source-compatible.

| # | Feature | Sketch | Plugs in at | Effort | Value |
|---|---|---|---|---|---|
| F1 | **`SoftContainer extends Box`** | `getFlatWidth/Length`, `getSealLoss`, `getSideSeam`, `getMaxFillThickness`, `getWrapFactor`, `getGusset`, `getProtection`; `atThickness(int): Box` | `Packer::addBox` (`src/Packer.php:120`) expands to candidate rigid boxes; existing packers run unchanged | S | Very high |
| F2 | **`PackedSoftPack`** | wraps `PackedBox`; `getFlatDimensions()`, `getFilledDimensions()`, `getMeasuredDimensions(MeasureMode)`, `getFillThickness()` | Built from `PackedBox::getUsedWidth/Length/Depth` (`src/PackedBox.php:96-133`) | S | High |
| F3 | **Rate profiles** (`ShippingProfile`/`PackedBoxCostCalculator` impls) | `ThicknessBand` (Royal Mail style), `FlatSumTier` (USPS soft pack), `DimWeight(divisor, roundUpPerDim, bulgeAllowance)`, composable, versioned data | `PackedBoxCostCalculator` (`src/PackedBoxCostCalculator.php`); Thorough already merges/splits boxes by cost, so threshold-hunting and split-to-save come free | M | Very high |
| F4 | **Item states: fold / roll / compress** | `SoftGoodItem extends Item { getSoftProfile(): SoftProfile }`; states in `OrientatedItem` + `PackedItem::$state` | Single seam: `OrientatedItemFactory::generatePermutations` (`src/OrientatedItemFactory.php:304`); extend the empty-box stability cache key | M | Very high |
| F5 | **Protection ladder** | `Protection { None, Poly, Padded, Rigid, Box }` on item (required) and container (offered); flags `sharp`, `liquid`, `noBend`, `fragile` map to a minimum | Container eligibility filter before packing (cheap), not per-placement | S | High |
| F6 | **Bundler** | Group soft goods into a stack/brick with `t = Σt_i·(1-stackCompression)`, optional vacuum ratio; packs as one composite item, output resolves back to SKUs | Preprocessing; precedent `LinkedItem` | M | High |
| F7 | **Rollables** | Cylinder states; identical rolls form a hex-packed block (`d + (n-1)·d·√3/2` height per n rows) | Block builder in `BlockPacker` (angled nesting is the precedent) | M | Medium |
| F8 | **Uncertainty-aware fit** | `thicknessSigma` per state; `setFitConfidence(0.95)` pads stack by `z·√Σσ²`; `SoftProfileCalibrator` (Welford running mean/variance per SKU-state) fed by `PackOutcomeRecorder` from the pack-station dimensioner | Sweep padding; calibrator is an interface plus in-memory impl, persistence is the host's job | M | Very high (differentiator) |
| F9 | **Parametric / right-size containers** | `ParametricBox` (min/max per axis, step, cost fn). v1: pack into max box, take `getUsed*` as the tight box. v2: candidate dims via normal patterns, minimise cost (surface area = Cainiao FBPP, material, dim weight) | `PackedBox::getUsed*`; `VolumePacker::packBestSubset` | M | Very high (the "outside the box" one) |
| F10 | **Mode planner + explainability** | Evaluate envelope / soft pack / box / custom, minimise landed cost = material + handling effort (state `effort`) + rated postage + risk (P(thickness > cap)·penalty). Return `RejectionReason`s ("hardcover: Protection::Rigid, mailer is Padded") | New orchestration over `Packer` | M-L | High |
| F11 | **Squishables** | Volume-conserving items filled into leftover maximal spaces after rigid placement (plush, bags of powder), with min-dimension limits | Post-pass in `ThoroughPacker` residual spaces | M | Medium |
| F12 | **Crush rating** | `maxLoadAbove` per item (the library has support-from-below via `SupportCalculator`, no load-bearing) | Native in block builder, or `ConstrainedPlacementItem` first | M | Medium |
| F13 | **Nestables and hollow items** | Nest pitch (`h + (n-1)·pitch`); items with a usable cavity (shoe holding socks) become sub-containers | Bundler / states | M | Medium |
| F14 | **Bag-in-box overpack** | Individual poly-bagging adds thickness/weight per item (Amazon rule: ≤3in slack) | Item states with `extraWeight` | S | Low-medium |
| F15 | **Visualiser** | Pillow mesh for soft packs, fold/roll glyphs, step-by-step fold instructions from `state` | `visualiser/visualiser.ts` | M | Medium |

### Item flag matrix

| Flag | Meaning | Solver effect |
|---|---|---|
| `foldable` | ladder of folds, each with measured `w×l×t` (not assumed volume-conserving) | states (F4) |
| `rollable` | diameter, length, band weight | cylinder states (F4/F7) |
| `compressible` | min ratio, max pressure, vacuum-ok | compression ladder / bundler (F4/F6) |
| `squishable` | volume-conserving, min dim | residual fill (F11) |
| `nestable` | pitch | stack states (F13) |
| `fragile` / `crushRating` | max load above | stacking rule (F12), protection (F5) |
| `sharp` / `liquid` / `noBend` | puncture, leak, must stay flat | protection ladder (F5) |
| `shipsInOwnContainer` | bypass | planner (F10) |

## 6. Roadmap

0. **F1+F2+F3 (minimal)+F5**: soft container, measured dims, thickness cap, protection filter, example rate profiles.
   Rigid-flat and already-flat items work immediately. Solver untouched.
1. **F4+F6**: states, bundler, `PackedItem::$state`, benchmark dataset.
2. **F8+F7**: uncertainty, calibrator, rolls.
3. **F9+F10**: right-size containers, planner, explainability.
4. F11-F15 as demand dictates.

Validation: add a `softpack` dataset to `bin/benchmark` (synthetic apparel orders, mailer catalogue, garment fold
tables); metrics: % orders soft-packed, cost/order, predicted vs measured thickness, burst rate. Calibration protocol:
measure ≥30 real packs per product class, fit κ and σ, report P95 prediction error.

## 7. Risks

* **The wrap model is an approximation.** Spike shows κ flips feasibility, so defaults must be conservative and the
  calibration path must exist before F4 is marketed as accurate.
* **Carrier rules move** (USPS softpack tiers and caps changed July 2026 per sources). Profiles are data.
* Sweep cost = boxes × candidate thicknesses × state combos; spike was 0.2 s for 4 mailers × ≤11 `t` × ≤18 state combos.
  Mitigate with normal-pattern candidates and early exit.
* Items with states change the stability-orientation cache key and `OrientatedItemSorter` tie-breaking; test heavily.
* Do not model true deformation in-process. Use physics offline to produce κ/curves.

## Sources

* Ecommerce mailer types and sizes: [EcoEnclose](https://www.ecoenclose.com/resources/definitive-guide-to-protective-mailers), [Value Mailers](https://www.valuemailers.com/bubble-mailer-size-guide/), [PAC kraft vs poly bubble](https://www.pac.com/how-to-make-a-smarter-choice-poly-bubble-mailers-vs-kraft-bubble-mailers/), [Packrift poly bag chart](https://packrift.com/pages/poly-bag-size-chart)
* Usable size vs listed, flap loss: [Plus Packaging](https://www.pluspackaging.com/blog/mailing-bags/how-to-measure-a-poly-mailer-for-shipping/), [Poly mailer sizes](https://amzprep.com/poly-mailers-sizes/)
* Royal Mail format limits: [Mailcoms](https://www.mailcoms.co.uk/news/royal-mail-size-guide-when-to-use-a-large-letter-vs-small-parcel/), [Priory Direct](https://www.priorydirect.co.uk/help-advice-centre/royal-mail-size-guide/)
* USPS cubic / soft pack: [Pirate Ship](https://support.pirateship.com/en/articles/15453569-july-2026-usps-rate-and-rule-changes), [PluginHive](https://www.pluginhive.com/usps-cubic-pricing/), [ShipWise](https://www.shipwise.com/blog/usps-cubic-pricing), [USPS DMM 223](https://pe.usps.com/text/dmm300/223.htm)
* UPS/FedEx dimension rounding and bulge: [Atlantic Packaging](https://www.atlanticpkg.com/dimensional-weight-rule-changes-fedex-rounds-up-ups-matches/), [Packwire](https://packwire.com/dim-weight-calculator)
* Amazon poly bag and suffocation rules: [Seller Essentials](https://selleressentials.com/poly-bags/), [EcoEnclose](https://www.ecoenclose.com/blog/suffocation-warnings-legal-packaging-requirements/)
* Vacuum compression claims: [Clean Gear Guide](https://cleangearguide.com/best-way-to-fold-clothes-for-vacuum-bags/)
* Garment folded sizes: [Foot Locker folding standards](https://www.footlocker-inc.com/content/dam/flincfoundation/footlockerinc_documents/vms/europe/Section%2004%20-%20Folding%20standards.pdf)
* Cartonization envelope routing (vendor claims): [Optioryx](https://www.optioryx.com/blog/best-cartonization-software-2026), [3DBinPacking](https://www.3dbinpacking.com/en/blog/cartonization-software-how-it-works/)
* 3D flexible bin packing (Cainiao): [arXiv 1804.06896](https://arxiv.org/abs/1804.06896)
* Deformable/irregular 3D packing: [arXiv 2206.15116](https://arxiv.org/pdf/2206.15116), [OPA-Pack](https://arxiv.org/html/2505.13339), [SDF-Pack](https://arxiv.org/pdf/2307.07356), [2D+1 packing DRL](https://arxiv.org/html/2503.17573v1)
* Circle packing density π/√12 ≈ 0.9069: [MathWorld](https://mathworld.wolfram.com/CirclePacking.html)
