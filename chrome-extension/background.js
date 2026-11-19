// Clicking the toolbar icon opens WebQA in the browser's side panel.
chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true }).catch(console.error);
