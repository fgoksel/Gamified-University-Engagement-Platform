# Event page: path and "Who has access" (Task #75)

Every event page shows **where the event sits in the tree** ("Path in the tree": the nodes from the top down to the node that owns the event), and the people who run the event also see a **"Who has access"** panel.

Nobody has access to one event on its own (26 October, Q2). A right is given on a node of the topic tree and covers the events there and below. So the panel lists everyone who holds **"Manage events"** on the event's owning node or on any node above it, and for each person the role and the node the right comes from:

- A person with rights from two nodes is listed once, with both, the nearest node first.
- A role that has ended is not counted, so the person leaves the panel when it ends. To remove someone, end the role on that node in the explorer ("One place to see and remove access").
- A deactivated account is not listed.
- System administrators can manage every event but hold no node, so they are not listed. The panel says so.
- A node above the viewer's own access is not named: the row says "a branch above your access", as in the explorer.

**Who sees the panel:** people who run some part of the event (create, manage, applications, points or QR codes on its node) and people who may see who has access at that node. A student who only applies sees the path and nothing about the managers. Their data is not even sent to the page.

The data comes from `App\Services\EventAccessPanel` through `EventController::show`; the panel is `Components/Events/EventAccessPanel.vue`.
