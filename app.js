var onLeaveMessage =
"The installation is not yet completed. Are you sure that you want to leave?";

var page = document.documentElement.getAttribute("id");
var screenEls = document.querySelectorAll(".screen");
var screens = {};
for (let i = 0; i < screenEls.length; i++) {
let el = screenEls[i];
screens[el.id.replace("screen-", "")] = {
    title: el.querySelector("h1").innerText
};
}

/**
* This function is case insensitive since Chrome (and maybe others) change the qs case on manual input.
* @param {string} name The parameter name in the query string.
* @return {boolean} True if the name is present in the query string.
*/
function locationHasParameter(name) {
var queryString = window.location.search.substring(1);
if (queryString) {
    var paramArray = queryString.split("&");
    for (let paramPair of paramArray) {
        var param = paramPair.split("=")[0];
        console.log(
            param,
            "^" + param + "$",
            new RegExp("^" + param + "$", "i").test(name)
        );
        if (new RegExp("^" + param + "$", "i").test(name)) {
            return true;
        }
    }
}
return false;
}

function escapeHtml(unsafe) {
return unsafe
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/\'/g, "&#039;");
}

var installer = {
uid: false,
data: {},
isCpanelDone: false,
isUpgradeToPaid: locationHasParameter("UpgradeToPaid"),
process: "install",
defaultScreen: "welcome",
init: function () {
    installer.log(runtime.serverString);
    if (this.isUpgradeToPaid) {
        this.process = "upgrade";
        this.defaultScreen = "upgrade";
    }
    var self = this;
    this.popScreen(this.defaultScreen);
    this.history.replace(this.defaultScreen);
    if (page != "error") {
        var inputEmailEls = document.querySelectorAll("input[type=email]");
        for (let inputEmailEl of inputEmailEls) {
            inputEmailEl.pattern = patterns.email_pattern;
        }
        this.bindActions();
    }
    document.addEventListener(
        "click",
        function (event) {
            if (!event.target.matches(".alert-close")) return;
            event.preventDefault();
            installer.popAlert();
        },
        false
    );
    window.onpopstate = function (e) {
        var isBack = installer.uid > e.state.uid;
        var isForward = !isBack;
        installer.uid = e.state.uid;

        var state = e.state;
        var form = installer.getShownScreenEl("form");
        if (isForward && form) {
            if (form.checkValidity()) {
                installer.actions[form.dataset.trigger](form.dataset.arg);
                return;
            } else {
                history.go(-1);
                var tmpSubmit = document.createElement("button");
                form.appendChild(tmpSubmit);
                tmpSubmit.click();
                form.removeChild(tmpSubmit);
                return;
            }
        }
        self.popScreen(state.view);
    };
    var forms = document.querySelectorAll("form");
    for (let i = 0; i < forms.length; i++) {
        forms[i].addEventListener(
            "submit",
            function (e) {
                e.preventDefault();
                e.stopPropagation();
                installer.actions[forms[i].dataset.trigger](forms[i].dataset.arg);
            },
            false
        );
    }
},
getCurrentScreen: function () {
    return this.getShownScreenEl("").id.replace("screen-", "");
},
getShownScreenEl: function (query) {
    return document.querySelector(".screen--show " + query);
},
shakeEl: function (el) {
    el.classList.remove("shake");
    setTimeout(function () {
        el.classList.add("shake");
    }, 1);
    setTimeout(function () {
        el.classList.remove("shake");
    }, 500);
},
pushAlert: function (message) {
    var pushiInnerHTML =
        "<span>" + message + \'</span><a class="alert-close"></a>\';
    var el = this.getShownScreenEl(".alert");
    var html = el.innerHTML;
    if (pushiInnerHTML == html) {
        this.shakeEl(el);
    } else {
        el.innerHTML = pushiInnerHTML;
    }
},
popAlert: function () {
    var el = this.getShownScreenEl(".alert");
    if (el) {
        el.innerHTML = "";
    }
},
getFormData: function () {
    var form = installer.getShownScreenEl("form");
    if (!form) {
        return;
    }
    var screen = this.getCurrentScreen();
    var inputEls = form.getElementsByTagName("input");
    var data = {};
    for (let inputEl of inputEls) {
        var id = inputEl.id.replace(screen, "");
        var key = id.charAt(0).toLowerCase() + id.slice(1);
        data[key] = inputEl.value;
    }
    return data;
},
writeFormData: function (screen, data) {
    installer.data[screen] = data ? data : this.getFormData();
},
bindActions: function () {
    var self = this;
    var triggers = document.querySelectorAll("[data-action]");
    for (let i = 0; i < triggers.length; i++) {
        var trigger = triggers[i];
        trigger.addEventListener("click", function (e) {
            var dataset = e.currentTarget.dataset;
            self.actions[dataset.action](dataset.arg);
        });
    }
},
history: {
    push: function (view) {
        this.writter("push", { view: view });
    },
    replace: function (view) {
        this.writter("replace", { view: view });
    },
    writter: function (fn, data) {
        data.uid = new Date().getTime();
        installer.uid = data.uid;
        switch (fn) {
            case "push":
                history.pushState(data, data.view);
                break;
            case "replace":
                history.replaceState(data, data.view);
                break;
        }
        document.title = screens[data.view].title; // Otherwise the titles at the browser bar could fail
        console.log("history.writter:", fn, data);
    }
},
/**
 *
 * @param {string} action
 * @param {object} params
 * @param {object} callback {success: fn(data), error: fn(data),}
 */
fetch: function (action, params, callback = {}) {
    var data = new FormData();
    data.append("action", action);
    for (var key in params) {
        data.append(key, params[key]);
    }
    var disableEls = document.querySelectorAll("button, input:not([data-disabled])");
    for (let disableEl of disableEls) {
        disableEl.disabled = true;
    }
    var box = this.getShownScreenEl(".flex-box");
    var loader = this.getShownScreenEl(".loader");
    if (!loader) {
        var loader = document.createElement("div");
        loader.classList.add("loader", "animate");
        box.insertBefore(loader, box.firstChild);
    }
    setTimeout(function () {
        loader.classList.add("loader--show");
    }, 1);
    ["always", "error"].forEach(function (value) {
        if (!(value in callback)) {
            let callbackFn =
                "fetchOn" + value.charAt(0).toUpperCase() + value.slice(1);
            callback[value] = installer[callbackFn];
        }
    });
    return fetch(runtime.installerFilename, {
        method: "POST",
        body: data
    })
        .then(function (response) {
            return response.text();
        })
        .then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                throw Error("Unable to parse server response. The installer is expecting a JSON response, but your server thrown this:<pre><code>" + escapeHtml(text) + "</code></pre> This is not normal and you should report it to our <a href=\'" + appUrl + "\' target=\'_blank\'>GitHub repository</a>.");
            }
        })
        .catch(error => {
            installer.pushAlert(error);
        })

        .then(function (data) {
            loader.classList.remove("loader--show");
            for (let disableEl of disableEls) {
                disableEl.disabled = false;
            }
            callback.always(data);
            let callbackRes;
            if (200 == data.code) {
                installer.popAlert();
                if ("success" in callback) {
                    callbackRes = callback.success(data);
                }
            } else {
                callbackRes = callback.error(data);
                if (true !== callbackRes) {
                    installer.pushAlert(data.message);
                    return new Promise(function (resolve, reject) {
                        reject(data);
                    });
                }
            }
            if (200 == data.code || true == callbackRes) {
                return new Promise(function (resolve, reject) {
                    resolve(data);
                });
            }
        });
    // .catch(error => {
    //   installer.pushAlert(error);
    // });
},
popScreen: function (screen) {
    console.log("popScreen:" + screen);
    var shownScreens = document.querySelectorAll(".screen--show");
    shownScreens.forEach(a => {
        a.classList.remove("screen--show");
    });
    document.querySelector("#screen-" + screen).classList.add("screen--show");
},
checkLicense: function (key, callback) {
    return this.fetch("checkLicense", { license: key }, callback);
},
fetchOnError: function (data) {
    if (installer.isInstalling()) {
        installer.abortInstall();
    }
},
fetchOnAlways: function (data) {
    installer.log(data.message);
},
fetchCommonInit: function () {
    this.log("Detecting existing cPanel .htaccess handlers");
    return this
        .fetch("cPanelHtaccessHandlers", null, {
            error: function () {
                return true;
            }
        })
        .then(json => {
            installer.data.cPanelHtaccessHandlers = "data" in json ? json.data.handlers : "";
        })
        .then(json => {
            installer.log("Downloading latest " + installer.data.software + " release");
            return installer.fetch("download", {
                software: installer.data.software,
                license: installer.data.license
            });
        })
        .then(json => {
            installer.log("Extracting " + json.data.fileBasename);
            return installer.fetch("extract", {
                software: installer.data.software,
                filePath: json.data.filePath,
                workingPath: runtime.absPath,
                appendHtaccess: installer.data.cPanelHtaccessHandlers,
            });
        });
},
fillInstallDetails: function (data) {
    let text = "+===================================+" + "\\n" +
        "| Chevereto installation            |" + "\\n" +
        "+===================================+" + "\\n" +
        "| URL: " + runtime.rootUrl + "\\n" +
        "| Software: " + data.software + "\\n" +
        "| --" + "\\n" +
        "| # Admin" + "\\n" +
        "| Email: " + data.admin.email + "\\n" +
        "| Username: " + data.admin.username + "\\n" +
        "| Password: " + data.admin.password + "\\n" +
        "| --" + "\\n" +
        "| # Database" + "\\n" +
        "| Host: " + data.db.host + "\\n" +
        "| Port: " + data.db.port + "\\n" +
        "| Name: " + data.db.name + "\\n" +
        "| User: " + data.db.user + "\\n" +
        "| User password: " + data.db.userPassword + "\\n" +
        "+===================================+";
    let el = document.createElement("pre");
    el.innerHTML = text;
    document.querySelector(".install-details").appendChild(el);
},
actions: {
    show: function (screen) {
        installer.popScreen(screen);
        if (history.state.view != screen) {
            installer.history.push(screen);
        }
    },
    setLicense: function (elId) {
        var licenseEl = document.getElementById(elId);
        var license = licenseEl.value;
        if (!license) {
            licenseEl.focus();
            installer.shakeEl(licenseEl);
            return;
        }
        installer.checkLicense(license, {
            success: function () {
                installer.data.license = license;
                installer.actions.setSoftware("chevereto");
            },
            error: function () {
                installer.data.license = null;
            }
        });
    },
    setSoftware: function (software) {
        document.body.classList.remove("sel--chevereto", "sel--chevereto-free");
        document.body.classList.add("sel--" + software);
        installer.data.software = software;
        installer.log("Software has been set to: " + software);
        this.show("cpanel");
    },
    setUpgrade: function () {
        console.log("setUpgrade");
        document.body.classList.remove("sel--chevereto-free");
        document.body.classList.add("sel--chevereto");
        var license = document.getElementById("upgradeKey").value;
        installer.checkLicense(license, {
            success: function () {
                installer.data.license = license;
                installer.actions.setSoftware("chevereto");
                installer.actions.show("ready-upgrade");
            },
            error: function () {
                installer.data.license = null;
            }
        });
    },
    cPanelProcess: function () {
        if (installer.isCpanelDone) {
            installer.actions.show("admin");
            return;
        }
        var els = {
            user: document.getElementById("cpanelUser"),
            password: document.getElementById("cpanelPassword")
        };
        var params = {};
        for (let key in els) {
            let el = els[key];
            if (!el.value) {
                el.focus();
                installer.shakeEl(el);
                return;
            } else {
                params[key] = el.value;
            }
        }
        installer.fetch("cPanelProcess", params, {
            error: function (data) {
                installer.isCpanelDone = false;
            }
        })
            .then(json => {
                for (let key in els) {
                    els[key].setAttribute("data-disabled", "");
                    els[key].disabled = true;
                }
                installer.writeFormData("db", json.data.db);
                installer.isCpanelDone = true;
                installer.actions.show("admin");
            });
    },
    setDb: function () {
        var params = installer.getFormData();
        installer.fetch("checkDatabase", params, {
            success: function (response, json) {
                installer.writeFormData("db", params);
                installer.actions.show("admin");
            },
            error: function (response, json) {
            }
        });
    },
    setAdmin: function () {
        installer.writeFormData("admin");
        this.show("emails");
    },
    setEmails: function () {
        installer.writeFormData("email");
        this.show("ready");
    },
    setReadyUpgrade() {
        this.show("ready-upgrade");
    },
    setReady: function () {
        this.show("ready");
    },
    upgrade: function () {
        installer.setBodyInstalling(true);
        this.show("upgrading");
        installer.log(
            "Downloading latest " + installer.data.software + " release"
        );
        installer
            .fetchCommonInit()
            .then(data => {
                installer.log(
                    "Removing installer file at " + runtime.installerFilepath
                );
                return installer.fetch("selfDestruct", null, {
                    error: function (data) {
                        var todo =
                            "Remove the installer file at " +
                            runtime.installerFilepath +
                            " and open " +
                            runtime.rootUrl +
                            " to continue the process.";
                        installer.pushAlert(todo);
                        installer.abortInstall(false);
                        return false;
                    }
                });
            })
            .then(data => {
                installer.setBodyInstalling(false);
                installer.log("Upgrade completed");
                setTimeout(function () {
                    installer.actions.show("complete-upgrade");
                }, 1000);
            });
    },
    install: function () {
        installer.setBodyInstalling(true);
        this.show("installing");

        installer
            .fetchCommonInit()
            .then(data => {
                installer.log("Creating app/settings.php file");
                let = params = Object.assign({ filePath: runtime.absPath + "app/settings.php" }, installer.data.db)
                return installer.fetch("createSettings", params);
            })
            .then(data => {
                installer.log("Performing system setup");
                let params = {
                    username: installer.data.admin.username,
                    email: installer.data.admin.email,
                    password: installer.data.admin.password,
                    email_from_email: installer.data.email.emailNoreply,
                    email_incoming_email: installer.data.email.emailInbox,
                    website_mode: \'community\',
                };
                return installer.fetch("submitInstallForm", params);
            })
            .then(data => {
                installer.log(
                    "Removing installer file at " + runtime.installerFilepath
                );
                return installer.fetch("selfDestruct", null, {
                    error: function (data) {
                        var todo =
                            "Remove the installer file at " +
                            runtime.installerFilepath +
                            " and open " +
                            runtime.rootUrl +
                            " to continue the process.";
                        installer.pushAlert(todo);
                        installer.abortInstall(false);
                        return false;
                    }
                });
            })
            .then(data => {
                installer.setBodyInstalling(false);
                installer.log("Installation completed");
                installer.fillInstallDetails(installer.data);
                setTimeout(function () {
                    installer.actions.show("complete");
                }, 1000);
            });
    }
},
setBodyInstalling: function (bool) {
    document.body.classList[bool ? "add" : "remove"]("body--installing");
},
isInstalling: function () {
    return document.body.classList.contains("body--installing");
},
abortInstall: function (message) {
    this.log(message ? message : "Process aborted");
    this.setBodyInstalling(false);
},
log: function (message) {
    var date = new Date();
    var t = {
        h: date.getHours(),
        m: date.getMinutes(),
        s: date.getSeconds()
    };
    for (var k in t) {
        if (t[k] < 10) {
            t[k] = "0" + t[k];
        }
    }
    var time = t.h + ":" + t.m + ":" + t.s;
    var el = document.querySelector(".log--" + (installer.isUpgradeToPaid ? "upgrade" : "install"));
    var p = document.createElement("p");
    var t = document.createTextNode(time + " " + message);
    p.appendChild(t);
    el.appendChild(p);
    el.scrollTop = el.scrollHeight;
}
};
if ("error" != document.querySelector("html").id) {
installer.init();
}
