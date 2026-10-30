const API_URL = "http://localhost:8000"; 

const errorBox = document.getElementById("error");
const infoBox = document.getElementById("info");
const title = document.getElementById("title");
const subtitle = document.getElementById("subtitle");

let pendingEmail = ""; // adresse en cours de vérification ou de réinitialisation

const TITLES = {
choice: ["Bienvenue", "Que veux-tu faire ?"],
register: ["Créer un compte", "Choisis un email et un mot de passe."],
verify: ["Vérifie ton email", ""],
login: ["Connexion", ""],
forgot: ["Mot de passe oublié", "On t'envoie un code par email pour prouver que le compte est à toi."],
reset: ["Nouveau mot de passe", ""],
};

function showError(msg) { errorBox.textContent = msg || ""; }
function showInfo(msg) { infoBox.textContent = msg || ""; }

// Affiche un écran et cache les autres
function show(name) {
document.querySelectorAll(".view").forEach((v) => { v.hidden = v.id !== "view-" + name; });
title.textContent = TITLES[name][0];
subtitle.textContent = TITLES[name][1];
if (name === "verify") subtitle.textContent = `Un code à 6 chiffres a été envoyé à ${pendingEmail}.`;
if (name === "reset") subtitle.textContent = `Si un compte existe pour ${pendingEmail}, un code vient d'être envoyé.`;
showError("");
showInfo("");
}

// Appel POST vers auth.php (le cookie de session est géré par le navigateur)
async function post(action, data) {
let res;
try {
    res = await fetch(`${API_URL}/auth.php?action=${action}`, {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(data),
    });
} catch {
    throw new Error("Serveur injoignable. Le backend est-il lancé ?");
}
const json = await res.json().catch(() => ({}));
if (!res.ok) {
    const err = new Error(json.error || "Erreur.");
    err.data = json;
    throw err;
}
return json;
}

// Exécute une action en désactivant le bouton et en affichant l'erreur éventuelle
async function run(button, fn) {
button.disabled = true;
showError("");
try {
    await fn();
} catch (e) {
    showError(e.message);
} finally {
    button.disabled = false;
}
}

// Bouton "Renvoyer le code" : bloqué 60 secondes après chaque envoi
function cooldown(button, seconds = 60) {
clearInterval(button._timer);
let left = seconds;
const label = "Renvoyer le code";
button.disabled = true;
button.textContent = `${label} (${left}s)`;
button._timer = setInterval(() => {
    left -= 1;
    if (left <= 0) {
    clearInterval(button._timer);
    button.disabled = false;
    button.textContent = label;
    } else {
    button.textContent = `${label} (${left}s)`;
    }
}, 1000);
}

const val = (id) => document.getElementById(id).value;

//Navigation
document.querySelectorAll("[data-go]").forEach((b) => {
b.addEventListener("click", () => {
    const target = b.dataset.go;
    if (target === "forgot") document.getElementById("forgot-email").value = val("login-email");
    show(target);
});
});

// Case "Afficher les mots de passe"
document.querySelectorAll(".toggle-pw").forEach((box) => {
box.addEventListener("change", () => {
    box.closest("form").querySelectorAll('input[autocomplete$="password"]').forEach((i) => {
    i.type = box.checked ? "text" : "password";
    });
});
});

//Inscription
const registerForm = document.getElementById("register-form");
registerForm.addEventListener("submit", (e) => {
e.preventDefault();
run(registerForm.querySelector("button[type=submit]"), async () => {
    if (val("register-password") !== val("register-confirm")) {
    throw new Error("Les deux mots de passe ne sont pas identiques.");
    }
    await post("register", {
    email: val("register-email"),
    password: val("register-password"),
    confirm: val("register-confirm"),
    });
    pendingEmail = val("register-email").trim().toLowerCase();
    show("verify");
    cooldown(document.getElementById("resend-verify"));
});
});

//Vérification de l'email
const verifyForm = document.getElementById("verify-form");
verifyForm.addEventListener("submit", (e) => {
e.preventDefault();
run(verifyForm.querySelector("button[type=submit]"), async () => {
    await post("verify", { email: pendingEmail, code: val("verify-code") });
    location.href = "index.html";
});
});

const resendVerify = document.getElementById("resend-verify");
resendVerify.addEventListener("click", async () => {
showError("");
try {
    await post("resend", { email: pendingEmail });
    showInfo("Un nouveau code vient d'être envoyé.");
    cooldown(resendVerify);
} catch (e) {
    showError(e.message);
}
});

//Connexion
const loginForm = document.getElementById("login-form");
loginForm.addEventListener("submit", (e) => {
e.preventDefault();
run(loginForm.querySelector("button[type=submit]"), async () => {
    try {
    await post("login", { email: val("login-email"), password: val("login-password") });
    location.href = "index.html";
    } catch (err) {
      if (err.data && err.data.needs_verification) { // compte jamais confirmé : on passe à la saisie du code
        pendingEmail = err.data.email;
        show("verify");
        showInfo(err.message);
        cooldown(resendVerify);
        return;
    }
    throw err;
    }
});
});

//Mot de passe oublié
const forgotForm = document.getElementById("forgot-form");
forgotForm.addEventListener("submit", (e) => {
e.preventDefault();
run(forgotForm.querySelector("button[type=submit]"), async () => {
    await post("forgot", { email: val("forgot-email") });
    pendingEmail = val("forgot-email").trim().toLowerCase();
    show("reset");
    cooldown(resendReset);
});
});

const resendReset = document.getElementById("resend-reset");
resendReset.addEventListener("click", async () => {
showError("");
try {
    await post("forgot", { email: pendingEmail });
    showInfo("Si un compte existe, un nouveau code vient d'être envoyé.");
    cooldown(resendReset);
} catch (e) {
    showError(e.message);
}
});

const resetForm = document.getElementById("reset-form");
resetForm.addEventListener("submit", (e) => {
e.preventDefault();
run(resetForm.querySelector("button[type=submit]"), async () => {
    if (val("reset-password") !== val("reset-confirm")) {
    throw new Error("Les deux mots de passe ne sont pas identiques.");
    }
    await post("reset", {
    email: pendingEmail,
    code: val("reset-code"),
    password: val("reset-password"),
    confirm: val("reset-confirm"),
    });
    document.getElementById("login-email").value = pendingEmail;
    show("login");
    showInfo("Mot de passe modifié. Tu peux te connecter.");
});
});

// ===== Démarrage =====
show("choice");

// Si déjà connecté alors on va directement au chat
fetch(`${API_URL}/auth.php?action=me`, { credentials: "include" })
.then((r) => { if (r.ok) location.href = "index.html"; })
.catch(() => {});