use std::path::Path;
use serde_derive::{Deserialize, Serialize};
use std::fs::File;
use serde_yaml::Value;
use std::collections::HashMap;

#[derive(Debug, Deserialize, Serialize)]
pub struct Settings {
    pub bind_address: String,
    pub server_port: u16,
    pub memory_limit: String,
    pub prefix: String,
    pub alert_message: String,
    pub timeout_message: String,
    pub kick_message: String,
    pub kick_broadcast: String,
    pub ban_expiration: String,
    pub ban_message: String,
    pub ban_broadcast: String,
    pub webhook: WebhookSettings,
    pub detections: HashMap<String, HashMap<String, HashMap<String, Value>>>

}

#[derive(Debug, Serialize, Deserialize)]
pub struct WebhookSettings {
    pub link: Option<String>,
    pub alerts: bool,
    pub punishments: bool
}

pub fn load(path: &Path) -> Result<Settings, Box<dyn std::error::Error>> {
    Ok(serde_yaml::from_reader::<File, Settings>(File::open(path)?)?)
}