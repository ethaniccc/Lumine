use std::io::Cursor;
use crate::varint::{WriteProtocolVarIntExt, ReadProtocolVarIntExt};
use std::convert::{TryFrom};
use crate::CanIo;
use crate::model::UUID;

#[test]
fn vari64() -> std::io::Result<()>{
    let buf: Vec<u8> = Vec::new();
    let mut cursor: Cursor<Vec<u8>> = Cursor::new(buf);
    let len = cursor.write_var_u64(1000)?;
    cursor.set_position(0);
    assert_eq!((1000, len), cursor.read_var_u64()?);
    Ok(())
}

#[test]
fn vari32() -> std::io::Result<()> {
    let buf: Vec<u8> = Vec::new();
    let mut cursor: Cursor<Vec<u8>> = Cursor::new(buf);
    let len = cursor.write_var_i32(-1000)?;
    cursor.set_position(0);
    assert_eq!((-1000, len), cursor.read_var_i32()?);
    Ok(())
}

#[test]
fn uuid() -> std::io::Result<()> {
    let mut buf: Vec<u8> = Vec::new();
    let uuid = UUID::try_from("12345563-1234-1234-1234-121432456642".to_string())?;
    println!("{:?}", uuid);
    uuid.write(&mut buf);
    let mut off: usize = 0;
    println!("{:?}", UUID::read(buf.as_slice(), &mut off).unwrap());
    Ok(())
}

#[test]
fn string() {
    let mut buf: Vec<u8> = Vec::new();
    "test".to_string().write(&mut buf);
    let mut off: usize = 0;
    println!("{:?}", buf);
    println!("{}", String::read(buf.as_slice(), &mut off).unwrap())
}

#[test]
fn login_pk() -> std::io::Result<()> {
    let pk = crate::packets::Packets::Login(crate::packets::Login {
        client_protocol: 433,
        connection_request: vec![122,22,22]
    });
    println!("{:?}", pk);
    let mut buf: Vec<u8> = vec![];
    pk.write(&mut buf);
    println!("{:?}", crate::packets::Packets::read(buf.as_slice(), &mut 0).unwrap());
    Ok(())
}

#[test]
fn text_pk() -> std::io::Result<()> {
    let pk = crate::packets::Packets::Text(crate::packets::Text {
        text_type: crate::packets::Type::TextTypeRaw,
        needs_translation: false,
        source_name: "Jviguy".to_string(),
        message: "Why you reading my test cases?".to_string(),
        parameters: vec!["test".to_string()],
        xuid: "cockandballs".to_string(),
        platform_chat_id: "bruh".to_string()
    });
    println!("{:?}", pk);
    let mut buf: Vec<u8> = vec![];
    pk.write(&mut buf);
    println!("{:?}", crate::packets::Packets::read(buf.as_slice(), &mut 0).unwrap());
    Ok(())
}