(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.copilotAgentTrackerWaitingOnKeithAutoRefresh = {
    attach: function (context) {
      once('copilot-agent-tracker-waitingonkeith', 'body', context).forEach(function () {
        var todo = document.getElementById('todo-for-keith');
        if (todo && typeof todo.scrollIntoView === 'function') {
          todo.scrollIntoView({ block: 'start' });
        }

        window.setTimeout(function () {
          window.location.reload();
        }, 10000);
      });
    }
  };
})(Drupal, once);
