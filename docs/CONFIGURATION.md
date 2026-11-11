# Configuration

**Configuration > Stamps4ZenCart**

| Setting | Default | Notes |
|---|---|---|
| Stamps.com Environment | production | **Production** makes real, paid labels with your Stamps.com account. **Staging** is Stamps.com's test system, for developers with a Stamps.com test account; never mail its labels. Each environment connects separately. |
| Connection Service URL | `https://myzencartzone.com/stamps-connect` | Leave as is. |
| Ship From: Name / Company | blank / store name | Printed on labels. At least one is required. |
| Ship From: Street Address, City, State, ZIP Code | blank | Required before buying labels. State is two letters. |
| Ship From: Address Line 2 | blank | Optional. |
| Ship From: Phone | blank | Required for USPS pickups. |
| Ship From: Email | store owner email | |
| Default Service | usps_ground_advantage | Preselected among the rates. |
| Default Package Type | package | **Package** needs length, width and height. |
| Delivery Confirmation | tracking | Signature options cost extra. |
| Product Weight Unit | pound | The unit your product weights use. |
| Label Size | 4x6 | 4x6 or letter. |
| Label Format | pdf | What Download PDF gives you: pdf or png. Print Label always prints an image at real size, on any printer, thermal label printers included. |
| Order Status After Label | Default (no change) | Production only. |
| Email Tracking to Customer | true | Production only. |
| Log Stamps.com Errors | true | `logs/stamps4zencart-YYYYMM.log`; never tokens, keys or addresses. |

## Staging

While the environment is **Staging**:

- Labels are marked as test labels in the order history.
- Customers are never emailed.
- Order statuses never change.
