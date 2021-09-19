use std::path::Path;
use serde_derive::{Deserialize, Serialize};
use tokio::io::{AsyncReadExt};
use std::io::BufReader;
use std::fs::File;

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
}

#[derive(Debug, Serialize, Deserialize)]
pub struct Detector {

}

#[derive(Debug, Serialize, Deserialize)]
pub struct WebhookSettings {
    pub link: Option<String>,
    pub alerts: bool,
    pub punishments: bool
}

pub async fn load(path: &Path) -> Result<Settings, Box<dyn std::error::Error>> {
    let mut f = File::open(path)?;
    Ok(serde_yaml::from_reader::<File, Settings>(f)?)
}