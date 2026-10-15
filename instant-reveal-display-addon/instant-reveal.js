document.addEventListener("DOMContentLoaded", function () {

  var settings = (typeof eiwrSettings !== "undefined") ? eiwrSettings : {};
  var loseText = settings.lose_text || "Sorry, better luck next time";

  document.querySelectorAll(".enhanced-instant-win-reveal").forEach(function (wrap) {

    var container = wrap.querySelector(".gift-reveal-container");
    var message   = wrap.querySelector(".instant-win-message");

    if (!container || !message) return;

    var opened = false;

    var instruction = wrap.querySelector(".eiwr-instruction");

    /* Tell the server the tickets in this block have been revealed. One click
       opens the whole item, so every ticket it covers is reported. The server
       sends the win email for any winning ticket and releases the order's held
       emails once the last ticket in the order is done — without this the
       reveal was purely cosmetic and no email ever went out. */
    function reportRevealed() {
      var ajaxUrl = settings.ajax_url || window.ajaxurl;
      var orderId = wrap.getAttribute("data-order-id");
      var ticketIds = (wrap.getAttribute("data-ticket-ids") || "")
        .split(",")
        .filter(function (id) { return id !== ""; });

      if (!ajaxUrl || !orderId || !ticketIds.length) return;

      /* One request for the whole item, not one per ticket. Sent in parallel the
         requests raced each other counting the reveals - every one saw only its
         own mark, so the order never reached its total and the held emails were
         never released. */
      var body = new URLSearchParams();
      body.append("action", "lty_reveal_instant_win");
      body.append("order_id", orderId);
      body.append("ticket_ids", ticketIds.join(","));

      fetch(ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString()
      }).catch(function () {
        /* the reveal itself must never be blocked by a failed report */
      });
    }

    function revealResult() {
      if (opened) return;
      opened = true;

      reportRevealed();

      setTimeout(function () {

        container.classList.add("hidden");
        if (instruction) instruction.classList.add("hidden");

        var hasWin = message.querySelectorAll("tbody tr").length > 0;

        if (hasWin) {
          message.classList.remove("hidden");
          spawnConfetti(wrap);
        } else {
          var p = document.createElement("p");
          p.className   = "eiwr-lose-msg";
          p.textContent = loseText;
          wrap.appendChild(p);
        }

      }, 650);
    }

    /* ── Gift box ── */
    var giftbox = wrap.querySelector(".eiwr-giftbox");
    if (giftbox) {
      var lid = giftbox.querySelector(".eiwr-gift-lid");

      giftbox.addEventListener("click", function () {
        if (opened) return;
        if (lid) lid.classList.add("eiwr-open");
        setTimeout(function () { giftbox.classList.add("eiwr-fade-out"); }, 350);
        revealResult();
      });
    }

    /* ── Envelope ── */
    var envelope = wrap.querySelector(".eiwr-envelope");
    if (envelope) {
      envelope.addEventListener("click", function () {
        if (opened) return;
        envelope.classList.add("eiwr-opening");
        revealResult();
      });
    }

    /* ── Flip card ── */
    var flipcard = wrap.querySelector(".eiwr-flipcard");
    if (flipcard) {
      var inner = flipcard.querySelector(".eiwr-flipcard-inner");

      flipcard.addEventListener("click", function () {
        if (opened) return;
        if (inner) inner.classList.add("eiwr-flipped");
        revealResult();
      });
    }

  });

  /* ── Confetti ── */
  function spawnConfetti(parent) {
    var colors = ["#A3EA41", "#38d4c0", "#ffd700", "#ff6b6b", "#4ecdc4", "#fff"];
    for (var i = 0; i < 22; i++) {
      (function (i) {
        setTimeout(function () {
          var el  = document.createElement("div");
          var sz  = 6 + Math.random() * 8;
          el.className = "eiwr-confetti";
          el.style.cssText = [
            "left:"             + (Math.random() * 100) + "%",
            "width:"            + sz + "px",
            "height:"           + sz + "px",
            "background:"       + colors[Math.floor(Math.random() * colors.length)],
            "border-radius:"    + (Math.random() > 0.5 ? "50%" : "2px"),
            "animation-duration:" + (0.7 + Math.random() * 0.8) + "s",
            "animation-delay:0s"
          ].join(";");
          parent.appendChild(el);
          setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 1600);
        }, i * 45);
      })(i);
    }
  }

});
